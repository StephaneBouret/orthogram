"""Local deployment contracts. Run: python tests/ops/deploy_beta_test.py.

Only a rewritten copy of the deployment script runs, below a fresh var/ directory.
PHP/Composer application commands are simulated there; the PHP config checker is
also exercised separately with the real Dotenv library and synthetic env files.
No production/local application env file is loaded; no database or remote HTTP endpoint is contacted.
"""
import hashlib
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import stat

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--assets-only", action="store_true", help="Only isolated public asset permission scenarios")
parser.add_argument("--maintenance-only", action="store_true", help="Only maintenance guards and preservation scenarios")
OPTIONS = parser.parse_args()

REPO = Path(__file__).resolve().parents[2]
BASH = os.environ.get("BASH_EXE") or shutil.which("bash") or r"C:\Program Files\Git\bin\bash.exe"
PHP = shutil.which("php")
ROOT = Path(tempfile.mkdtemp(prefix="deploy-beta-test-", dir=REPO / "var"))
ENV = {k: v for k, v in os.environ.items() if not k.startswith(("DICTATION_", "COURSE_STORAGE_", "APP_", "SYMFONY_", "ORTHOGRAM_"))}
ENV["XDEBUG_MODE"] = "off"
# MSYS emulated symlinks exercise Bash -L/realpath even without Windows symlink privilege.
ENV["MSYS"] = "winsymlinks:sys"


def run(args, cwd=ROOT, env=ENV):
    return subprocess.run(args, cwd=cwd, env=env, text=True, encoding="utf-8", capture_output=True)


def posix(path):
    if os.name != "nt":
        return str(path)
    return run([str(Path(BASH).parent.parent / "usr/bin/cygpath.exe"), "-u", str(path)]).stdout.strip()


def write(path, text):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text, encoding="utf-8", newline="\n")


def expect(result, success):
    assert (result.returncode == 0) == success, result.stdout + result.stderr


original = (REPO / "ops/deploy_beta.sh").read_text(encoding="utf-8")
assert original.count("php bin/console doctrine:migrations:migrate ") == 1
expect(run([BASH, "-n", str(REPO / "ops/deploy_beta.sh")]), True)
ASSET_SCENARIOS = ["assets-umask", "assets-root-link", "assets-parent-link", "assets-chmod-failure"]
SCENARIOS = ["success", "deferred", "bad-deferral", "initial", "missing-maintenance", "missing-confirmation", "bad-source", "bad-compiled", "bad-container", "platform-failure", "migration-failure", "storage-file", "storage-link", "dangling-link", "ancestor-link", "nested-link", "create-storage"]
URLS = ["https://beta.orthogram.fr/", "https://beta.orthogram.fr/login", "https://beta.orthogram.fr/admin",
        "https://beta.orthogram.fr/public/", "https://beta.orthogram.fr/public/index.php",
        "https://orthogram.fr/beta/public/index.php"]
# Fail each individual GET/HEAD at entry and immediately before migrations.
HTTP_FAILURES = {f"http-{phase}-{method}-{index}": (phase, method, url, "200", 0)
                 for phase in (1, 2) for method in ("GET", "HEAD") for index, url in enumerate(URLS)}
HTTP_FAILURES.update({
    "http-redirect": (2, "HEAD", URLS[-1], "302", 0),
    "http-server-error": (2, "GET", URLS[0], "500", 0),
    "http-zero": (1, "HEAD", URLS[0], "000", 0),
    "http-malformed": (2, "HEAD", URLS[-1], "503503", 0),
    "http-timeout": (2, "HEAD", URLS[-1], "000", 28),
    "http-tls-error": (1, "GET", URLS[0], "503", 60),
    "http-final-failure": (3, "HEAD", URLS[-1], "200", 0),
})
MAINTENANCE_SCENARIOS = ["missing-project-marker", "missing-public-marker", "lost-project-marker", "lost-public-marker"] + list(HTTP_FAILURES)
public_htaccess = (REPO / "public/.htaccess").read_text(encoding="utf-8")
maintenance_block = public_htaccess.split("\n\n", 1)[0]
assert "RewriteCond %{DOCUMENT_ROOT}/.orthogram-maintenance -f [OR]\n    RewriteCond /home/u849885333/domains/orthogram.fr/.orthogram-maintenance -f\n    RewriteRule ^ - [R=503,L]" in maintenance_block
assert maintenance_block in original
scenarios = ASSET_SCENARIOS if OPTIONS.assets_only else (
    ["success", "initial", "migration-failure", "missing-maintenance"] + MAINTENANCE_SCENARIOS
    if OPTIONS.maintenance_only else SCENARIOS + ASSET_SCENARIOS + MAINTENANCE_SCENARIOS)
for scenario in scenarios:
    root = ROOT / scenario
    project = root / "public_html/beta"
    courses = root / "private/orthogram/courses"
    storage = root / "private/orthogram/dictations"
    write(project / "public/images/avatars/user.jpg", "avatar preserved")
    (project / "var/cache").mkdir(parents=True)
    write(root / ".env.beta", "synthetic config only")
    write(root / ".orthogram-maintenance", "keep maintenance")
    write(project / ".orthogram-maintenance", "keep project maintenance")
    write(project / "public/.orthogram-maintenance", "keep public maintenance")
    write(project / "public/.htaccess", public_htaccess)
    marker_contents = {path: path.read_bytes() for path in (
        root / ".orthogram-maintenance", project / ".orthogram-maintenance", project / "public/.orthogram-maintenance")}
    for category in ("files", "audios", "videos"):
        (courses / category).mkdir(parents=True)
    write(courses / ".orthogram-private-ready", "orthogram-private-courses-v1\n")
    write(storage / "existing.mp3", "existing audio bytes")
    before = hashlib.sha256((storage / "existing.mp3").read_bytes()).hexdigest()
    for name in ("php", "composer"):
        write(root / "mock-bin" / name, """#!/bin/bash
printf '%s %s\\n' "${0##*/}" "$*" >> "$MOCK_LOG"
case "$SCENARIO:$*" in
    bad-source:*check_beta_environment.php\ source*) exit 11;;
    bad-compiled:*check_beta_environment.php\ compiled*) exit 12;;
    bad-container:*check_beta_environment.php\ container*) exit 13;;
    platform-failure:check-platform-reqs*) exit 14;;
    migration-failure:*doctrine:migrations:migrate*) exit 15;;
    lost-project-marker:*check_beta_environment.php\ container*) rm -- .orthogram-maintenance;;
    lost-public-marker:*check_beta_environment.php\ container*) rm -- public/.orthogram-maintenance;;
esac
if [[ "$*" == 'bin/console asset-map:compile --env=prod --no-debug' ]]; then
    case "$SCENARIO" in
        assets-root-link) ln -s "$ASSET_PRIVATE" public/assets; exit 0;;
        assets-parent-link) mv public "$ASSET_MOVED_PUBLIC"; ln -s "$ASSET_MOVED_PUBLIC" public;;
    esac
    mkdir -p public/assets/vendor/bootstrap public/assets/styles
    printf 'bootstrap' > public/assets/vendor/bootstrap/bootstrap.css
    printf 'courses' > public/assets/styles/courses.css
    printf 'dictations' > public/assets/styles/dictations.css
    if [[ "$SCENARIO" == assets-* ]]; then
        stat -c '%a' public/assets public/assets/styles/courses.css > "$ASSET_BEFORE"
        ln -s "$ASSET_PRIVATE" public/assets/private-link
        ln -s "$ASSET_ENV" public/assets/env-link
        ln -s "$ASSET_PRIVATE/missing" public/assets/dangling-link
    fi
fi
exit 0
""")
        (root / "mock-bin" / name).chmod(0o755)
    # Never fall through to real curl: assert its invocation, then simulate the HTTP boundary.
    write(root / "mock-bin/curl", r'''#!/bin/bash
set -eu
[[ "$1" == --disable ]] || exit 90
method=GET
connect_timeout=0
max_time=0
no_cache=0
for arg in "$@"; do
    case "$arg" in
        --head) method=HEAD;;
        --connect-timeout) connect_timeout=1;;
        --max-time) max_time=1;;
        'Cache-Control: no-cache') no_cache=1;;
        -L|--location|-k|--insecure|-f|--fail|--retry) exit 91;;
    esac
done
[[ "$connect_timeout$max_time$no_cache" == 111 ]] || exit 92
url="${!#}"
[[ "$url" == *'?orthogram_maintenance_check='* ]] || exit 93
url="${url%%\?*}"
count=0
[[ ! -f "$HTTP_COUNT" ]] || read -r count < "$HTTP_COUNT"
count=$((count + 1))
printf '%s\n' "$count" > "$HTTP_COUNT"
phase=$(((count - 1) / 12 + 1))
printf 'http %s %s %s\n' "$phase" "$method" "$url" >> "$MOCK_LOG"
if [[ "$phase" == "$HTTP_BAD_PHASE" && "$method" == "$HTTP_BAD_METHOD" && "$url" == "$HTTP_BAD_URL" ]]; then
    printf '%s' "$HTTP_BAD_STATUS"
    exit "$HTTP_BAD_EXIT"
fi
printf '503'
''')
    (root / "mock-bin/curl").chmod(0o755)
    if scenario == "missing-maintenance":
        (root / ".orthogram-maintenance").unlink()
    if scenario == "missing-project-marker":
        (project / ".orthogram-maintenance").unlink()
    if scenario == "missing-public-marker":
        (project / "public/.orthogram-maintenance").unlink()
    if scenario in ("storage-file", "storage-link", "dangling-link", "create-storage"):
        (storage / "existing.mp3").unlink()
        storage.rmdir()
    if scenario == "storage-file":
        write(storage, "not a directory")
    if scenario in ("storage-link", "dangling-link", "nested-link", "ancestor-link"):
        target = root / "outside"
        target.mkdir()
        if scenario == "ancestor-link":
            parent = storage.parent
            moved = root / "moved-private"
            parent.rename(moved)
            target, link = moved, parent
        elif scenario == "nested-link":
            link = storage / "linked.mp3"
        else:
            link = storage
            if scenario == "dangling-link":
                target = root / "absent"
        expect(run([BASH, "-c", 'ln -s -- "$1" "$2" && test -L "$2"', "test", posix(target), posix(link)]), True)
    if scenario == "initial":
        (courses / ".orthogram-private-ready").unlink()
        (root / "public_html/shared/courses").mkdir(parents=True)
    script = original.replace("/home/u849885333/domains/orthogram.fr", posix(root))
    assert "/home/u849885333" not in script
    write(root / "deploy.sh", script)
    env = ENV | {
        "PATH": str(root / "mock-bin") + os.pathsep + ENV["PATH"],
        "MOCK_LOG": posix(root / "commands.log"), "SCENARIO": scenario,
        "MOCK_BIN": posix(root / "mock-bin"), "HTTP_COUNT": posix(root / "http-count"),
        "HTTP_BAD_PHASE": "0", "HTTP_BAD_METHOD": "", "HTTP_BAD_URL": "", "HTTP_BAD_STATUS": "", "HTTP_BAD_EXIT": "0",
        "ORTHOGRAM_MAINTENANCE_CONFIRMED": "1",
        "ORTHOGRAM_PRIVATE_STORAGE_VERIFIED": "1", "ORTHOGRAM_LEGACY_HTTP_BLOCKED": "1",
    }
    if scenario in HTTP_FAILURES:
        env.update(dict(zip(("HTTP_BAD_PHASE", "HTTP_BAD_METHOD", "HTTP_BAD_URL", "HTTP_BAD_STATUS", "HTTP_BAD_EXIT"),
                            map(str, HTTP_FAILURES[scenario]))))
    if scenario in ASSET_SCENARIOS:
        write(root / "private/orthogram/courses/files/course.pdf", "private course")
        protected = [storage / "existing.mp3", root / ".env.beta", root / ".orthogram-maintenance",
                     courses / "files/course.pdf"]
        for path in protected:
            path.chmod(0o600)
        protected_before = {path: (path.read_bytes(), stat.S_IMODE(path.stat().st_mode)) for path in protected}
        env.update({"ASSET_PRIVATE": posix(storage), "ASSET_ENV": posix(root / ".env.beta"),
                    "ASSET_MOVED_PUBLIC": posix(root / "moved-public"),
                    "ASSET_BEFORE": posix(root / "asset-before.log"),
                    "ASSET_CHMOD_LOG": posix(root / "asset-chmod.log"),
                    "ASSET_MOCK_BIN": posix(root / "mock-bin"),
                    "REAL_CHMOD": run([BASH, "-c", "command -v chmod"]).stdout.strip()})
        write(root / "mock-bin/chmod", """#!/bin/bash
printf '%s\\t' "$@" >> "$ASSET_CHMOD_LOG"
printf '\\n' >> "$ASSET_CHMOD_LOG"
if [[ "$SCENARIO" == assets-chmod-failure && "$*" == *public/assets* ]]; then exit 16; fi
exec "$REAL_CHMOD" "$@"
""")
        (root / "mock-bin/chmod").chmod(0o755)
    if scenario == "missing-confirmation":
        env.pop("ORTHOGRAM_PRIVATE_STORAGE_VERIFIED")
    if scenario in ("deferred", "bad-deferral"):
        env["ORTHOGRAM_DICTATION_WEB_DEFERRED"] = "1" if scenario == "deferred" else "maybe"
    args = [str(root / "deploy.sh"), "--initial-switch" if scenario == "initial" else "--deploy"]
    # Git Bash prepends its tools to PATH on startup; put the mocks first afterwards.
    result = run([BASH, "-c", 'export PATH="$MOCK_BIN:$PATH"; if [[ "$SCENARIO" == assets-* ]]; then umask 077; fi; source "$1" "${@:2}"', "test", *args], env=env)
    success = scenario in ("success", "deferred", "initial", "create-storage", "assets-umask")
    expect(result, success)
    log = (root / "commands.log").read_text() if (root / "commands.log").exists() else ""
    for path, contents in marker_contents.items():
        absent = ((scenario == "missing-maintenance" and path == root / ".orthogram-maintenance")
                  or (scenario in ("missing-project-marker", "lost-project-marker") and path == project / ".orthogram-maintenance")
                  or (scenario in ("missing-public-marker", "lost-public-marker") and path == project / "public/.orthogram-maintenance"))
        if absent:
            assert not path.exists(), "The script must not silently recreate a lost marker"
        else:
            # Python cannot follow MSYS-emulated links on Windows; inspect the
            # actual target moved by the existing assets-parent-link fixture.
            actual_path = (root / "moved-public/.orthogram-maintenance"
                           if scenario == "assets-parent-link" and path == project / "public/.orthogram-maintenance" else path)
            assert actual_path.read_bytes() == contents, "All remaining markers must survive success or failure"
    if scenario not in ("storage-file", "storage-link", "dangling-link", "create-storage", "ancestor-link"):
        assert hashlib.sha256((storage / "existing.mp3").read_bytes()).hexdigest() == before
    assert log.count("doctrine:migrations:migrate") == (1 if success or scenario in ("migration-failure", "http-final-failure") or scenario in ASSET_SCENARIOS else 0)
    http_calls = [line for line in log.splitlines() if line.startswith("http ")]
    if success:
        assert http_calls == [f"http {phase} {method} {url}" for phase in (1, 2, 3) for url in URLS for method in ("GET", "HEAD")]
        lines = log.splitlines()
        migration_index = next(i for i, line in enumerate(lines) if "doctrine:migrations:migrate" in line)
        assert lines[migration_index - 1] == f"http 2 HEAD {URLS[-1]}"
        assert (project / ".htaccess").read_text().startswith(maintenance_block.replace("/home/u849885333/domains/orthogram.fr", posix(root)))
    if scenario in HTTP_FAILURES:
        phase, method, url, _, _ = HTTP_FAILURES[scenario]
        assert http_calls[-1] == f"http {phase} {method} {url}"
        assert "Maintenance HTTP check failed" in result.stderr
        if phase == 1:
            assert all(line.startswith("http ") for line in log.splitlines()), "Early failure must precede application commands"
    if scenario in ("lost-project-marker", "lost-public-marker"):
        assert len(http_calls) == 12
        assert "Missing or linked maintenance marker" in result.stderr
    if scenario == "http-final-failure":
        assert "Code bêta préparé" not in result.stdout
    if scenario == "migration-failure":
        assert "app:avatars:repair" not in log and "asset-map:compile" not in log
    if scenario != "initial":
        assert "transfer-course-files" not in log
    else:
        assert log.count("transfer-course-files") == 3
        assert (courses / ".orthogram-private-ready").read_text() == "orthogram-private-courses-v1\n"
    if success:
        assert (root / "public_html/shared/avatars/user.jpg").read_text() == "avatar preserved"
    if scenario == "deferred":
        assert "PHP web NON VALIDÉ" in result.stdout
    if scenario in ASSET_SCENARIOS:
        assert {path: (path.read_bytes(), stat.S_IMODE(path.stat().st_mode)) for path in protected} == protected_before
        calls = [line.split("\t")[:-1] for line in (root / "asset-chmod.log").read_text().splitlines()]
        asset_calls = [call for call in calls if any(path.startswith("public/assets") for path in call)]
        if scenario in ("assets-root-link", "assets-parent-link"):
            assert not asset_calls, asset_calls
        if scenario == "assets-chmod-failure":
            assert asset_calls and 'Code bêta préparé' not in result.stdout
        if scenario == "assets-umask":
            assert (root / "asset-before.log").read_text().splitlines() == ["700", "600"]
            expected = {"public/assets": "755", "public/assets/vendor": "755",
                        "public/assets/vendor/bootstrap": "755", "public/assets/styles": "755",
                        "public/assets/vendor/bootstrap/bootstrap.css": "644",
                        "public/assets/styles/courses.css": "644", "public/assets/styles/dictations.css": "644"}
            actual = {}
            for mode, separator, *paths in asset_calls:
                assert separator == "--"
                for path in paths:
                    assert path not in actual
                    actual[path] = mode
            assert actual == expected, actual  # Includes no link or private/env target.
            if os.name == "nt":
                print("SKIP actual POSIX chmod modes on Windows/MSYS; exact chmod targets/modes verified")
            else:
                for path, mode in expected.items():
                    assert stat.S_IMODE((project / path).stat().st_mode) == int(mode, 8), path
            assert (project / "public/assets/vendor/bootstrap/bootstrap.css").read_text() == "bootstrap"
            assert (project / "public/assets/styles/courses.css").read_text() == "courses"
            assert (project / "public/assets/styles/dictations.css").read_text() == "dictations"
    print("PASS shell", scenario)

if OPTIONS.assets_only or OPTIONS.maintenance_only:
    print("Fixtures retained for inspection:", ROOT)
    raise SystemExit(0)

# Real PHP/Dotenv, no kernel or real application .env.local involved.
fixture = ROOT / "php-config"
write(fixture / "vendor/autoload.php", "<?php require " + json.dumps((REPO / "vendor/autoload.php").as_posix()) + ";\n")
checker = str(REPO / "ops/check_beta_environment.php")
values = {"APP_ENV": "prod", "APP_DEBUG": "0", "COURSE_STORAGE_DIR": "/private/courses", "DICTATION_STORAGE_DIR": "/private/dictations", "DICTATION_LEARNER_ENABLED": "0"}
for scenario in ("valid-zero", "valid-one", "deferred-zero", "deferred-one", "bad-deferral", "bad-path", "missing-flag", "bad-flag", "process-override", "copy-mismatch", "dump-mismatch", "malformed-secret"):
    source = values.copy()
    copied = values.copy()
    compiled = values.copy()
    env = ENV.copy()
    if scenario in ("valid-one", "deferred-one"):
        source["DICTATION_LEARNER_ENABLED"] = copied["DICTATION_LEARNER_ENABLED"] = compiled["DICTATION_LEARNER_ENABLED"] = "1"
    if scenario.startswith("deferred-") or scenario == "bad-deferral":
        env["ORTHOGRAM_DICTATION_WEB_DEFERRED"] = "maybe" if scenario == "bad-deferral" else "1"
    if scenario == "bad-path":
        source["DICTATION_STORAGE_DIR"] = "/public/dictations"
    if scenario == "missing-flag":
        source.pop("DICTATION_LEARNER_ENABLED")
    if scenario == "bad-flag":
        source["DICTATION_LEARNER_ENABLED"] = "maybe"
    if scenario == "process-override":
        env["DICTATION_LEARNER_ENABLED"] = "1"
    if scenario == "copy-mismatch":
        copied["DICTATION_STORAGE_DIR"] = "/wrong"
    if scenario == "dump-mismatch":
        compiled["DICTATION_STORAGE_DIR"] = "/wrong"
    write(fixture / ".env.beta", "\n".join(f"{k}={v}" for k, v in source.items()) + "\n")
    if scenario == "malformed-secret":
        write(fixture / ".env.beta", 'SECRET="DO_NOT_PRINT_THIS_SECRET\n')
    write(fixture / ".env", "APP_ENV=prod\nAPP_DEBUG=0\n")
    write(fixture / ".env.local", "\n".join(f"{k}={v}" for k, v in copied.items()) + "\n")
    write(fixture / ".env.local.php", "<?php return json_decode('" + json.dumps(compiled) + "', true);\n")
    result = run([PHP, checker, "compiled", str(fixture / ".env.beta"), "/private/courses", "/private/dictations"], cwd=fixture, env=env)
    expect(result, scenario.startswith("valid-") or scenario == "deferred-zero")
    assert "DO_NOT_PRINT_THIS_SECRET" not in result.stdout + result.stderr
    print("PASS PHP", scenario)

print("Fixtures retained for inspection:", ROOT)

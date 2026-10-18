"""Local deployment contracts. Run: python tests/ops/deploy_beta_test.py.

Only a rewritten copy of the deployment script runs, below a fresh var/ directory.
PHP/Composer application commands are simulated there; the PHP config checker is
also exercised separately with the real Dotenv library and synthetic env files.
No production/local application env file is loaded; no database is contacted.
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
for scenario in ASSET_SCENARIOS if OPTIONS.assets_only else SCENARIOS + ASSET_SCENARIOS:
    root = ROOT / scenario
    project = root / "public_html/beta"
    courses = root / "private/orthogram/courses"
    storage = root / "private/orthogram/dictations"
    write(project / "public/images/avatars/user.jpg", "avatar preserved")
    (project / "var/cache").mkdir(parents=True)
    write(root / ".env.beta", "synthetic config only")
    write(root / ".orthogram-maintenance", "keep maintenance")
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
    if scenario == "missing-maintenance":
        (root / ".orthogram-maintenance").unlink()
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
        "ORTHOGRAM_MAINTENANCE_CONFIRMED": "1",
        "ORTHOGRAM_PRIVATE_STORAGE_VERIFIED": "1", "ORTHOGRAM_LEGACY_HTTP_BLOCKED": "1",
    }
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
    result = run([BASH, "-c", 'export PATH="$ASSET_MOCK_BIN:$PATH"; umask 077; source "$1" "${@:2}"', "test", *args] if scenario in ASSET_SCENARIOS else [BASH, *args], env=env)
    success = scenario in ("success", "deferred", "initial", "create-storage", "assets-umask")
    expect(result, success)
    log = (root / "commands.log").read_text() if (root / "commands.log").exists() else ""
    if scenario != "missing-maintenance":
        assert (root / ".orthogram-maintenance").read_text() == "keep maintenance"
    if scenario not in ("storage-file", "storage-link", "dangling-link", "create-storage", "ancestor-link"):
        assert hashlib.sha256((storage / "existing.mp3").read_bytes()).hexdigest() == before
    assert log.count("doctrine:migrations:migrate") == (1 if success or scenario == "migration-failure" or scenario in ASSET_SCENARIOS else 0)
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

if OPTIONS.assets_only:
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

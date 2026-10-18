"""Local deployment contracts. Run: python tests/ops/deploy_beta_test.py.

Only a rewritten copy of the deployment script runs, below a fresh var/ directory.
PHP/Composer application commands are simulated there; the PHP config checker is
also exercised separately with the real Dotenv library and synthetic env files.
No production/local application env file is loaded; no database is contacted.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

REPO = Path(__file__).resolve().parents[2]
BASH = os.environ.get("BASH_EXE") or shutil.which("bash") or r"C:\Program Files\Git\bin\bash.exe"
PHP = shutil.which("php")
ROOT = Path(tempfile.mkdtemp(prefix="deploy-beta-test-", dir=REPO / "var"))
ENV = {k: v for k, v in os.environ.items() if not k.startswith(("DICTATION_", "COURSE_STORAGE_", "APP_", "SYMFONY_"))}
ENV["XDEBUG_MODE"] = "off"
# MSYS emulated symlinks exercise Bash -L/realpath even without Windows symlink privilege.
ENV["MSYS"] = "winsymlinks:sys"


def run(args, cwd=ROOT, env=ENV):
    return subprocess.run(args, cwd=cwd, env=env, text=True, capture_output=True)


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
for scenario in ["success", "initial", "missing-maintenance", "missing-confirmation", "bad-source", "bad-compiled", "bad-container", "platform-failure", "migration-failure", "storage-file", "storage-link", "dangling-link", "ancestor-link", "nested-link", "create-storage"]:
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
    if scenario == "missing-confirmation":
        env.pop("ORTHOGRAM_PRIVATE_STORAGE_VERIFIED")
    result = run([BASH, str(root / "deploy.sh"), "--initial-switch" if scenario == "initial" else "--deploy"], env=env)
    success = scenario in ("success", "initial", "create-storage")
    expect(result, success)
    log = (root / "commands.log").read_text() if (root / "commands.log").exists() else ""
    if scenario != "missing-maintenance":
        assert (root / ".orthogram-maintenance").read_text() == "keep maintenance"
    if scenario not in ("storage-file", "storage-link", "dangling-link", "create-storage", "ancestor-link"):
        assert hashlib.sha256((storage / "existing.mp3").read_bytes()).hexdigest() == before
    assert log.count("doctrine:migrations:migrate") == (1 if success or scenario == "migration-failure" else 0)
    if scenario == "migration-failure":
        assert "app:avatars:repair" not in log and "asset-map:compile" not in log
    if scenario != "initial":
        assert "transfer-course-files" not in log
    else:
        assert log.count("transfer-course-files") == 3
        assert (courses / ".orthogram-private-ready").read_text() == "orthogram-private-courses-v1\n"
    if success:
        assert (root / "public_html/shared/avatars/user.jpg").read_text() == "avatar preserved"
    print("PASS shell", scenario)

# Real PHP/Dotenv, no kernel or real application .env.local involved.
fixture = ROOT / "php-config"
write(fixture / "vendor/autoload.php", "<?php require " + json.dumps((REPO / "vendor/autoload.php").as_posix()) + ";\n")
checker = str(REPO / "ops/check_beta_environment.php")
values = {"APP_ENV": "prod", "APP_DEBUG": "0", "COURSE_STORAGE_DIR": "/private/courses", "DICTATION_STORAGE_DIR": "/private/dictations", "DICTATION_LEARNER_ENABLED": "0"}
for scenario in ("valid-zero", "valid-one", "bad-path", "missing-flag", "bad-flag", "process-override", "copy-mismatch", "dump-mismatch", "malformed-secret"):
    source = values.copy()
    copied = values.copy()
    compiled = values.copy()
    env = ENV.copy()
    if scenario == "valid-one":
        source["DICTATION_LEARNER_ENABLED"] = copied["DICTATION_LEARNER_ENABLED"] = compiled["DICTATION_LEARNER_ENABLED"] = "1"
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
    expect(result, scenario.startswith("valid-"))
    assert "DO_NOT_PRINT_THIS_SECRET" not in result.stdout + result.stderr
    print("PASS PHP", scenario)

print("Fixtures retained for inspection:", ROOT)

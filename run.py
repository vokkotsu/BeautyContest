import subprocess
import sys
import os
import signal

ROOT = os.path.dirname(os.path.abspath(__file__))
BACKEND = os.path.join(ROOT, "backend")
FRONTEND = os.path.join(ROOT, "frontend")

procs = []

def cleanup():
    for p in procs:
        try:
            p.terminate()
        except Exception:
            pass
    for p in procs:
        try:
            p.wait(timeout=5)
        except Exception:
            try:
                p.kill()
            except Exception:
                pass

def main():
    # Backend
    procs.append(subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "main:app", "--reload", "--host", "0.0.0.0", "--port", "8000"],
        cwd=BACKEND,
    ))
    # Frontend
    npm = "npm.cmd" if os.name == "nt" else "npm"
    procs.append(subprocess.Popen(
        [npm, "run", "dev"],
        cwd=FRONTEND,
        shell=True,
    ))

    print("Backend:  http://localhost:8000")
    print("Frontend: http://localhost:5173")
    print("Press Ctrl+C to stop.")

    try:
        for p in procs:
            p.wait()
    except KeyboardInterrupt:
        print("\nStopping...")
    finally:
        cleanup()

if __name__ == "__main__":
    main()
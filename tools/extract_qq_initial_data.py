from __future__ import annotations

import json
from pathlib import Path


def find_largest_capture() -> Path:
    qq_dir = Path(__file__).resolve().parents[1] / "qq"
    files = sorted(qq_dir.glob("*.txt"), key=lambda p: p.stat().st_size, reverse=True)
    if not files:
        raise SystemExit("no qq/*.txt files")
    return files[0]


def extract_initial_data(text: str) -> dict:
    tag = "window.__INITIAL_DATA__ ="
    i = text.find(tag)
    if i == -1:
        raise ValueError("window.__INITIAL_DATA__ not found")
    jstart = text.find("{", i)
    if jstart == -1:
        raise ValueError("initial JSON start not found")

    depth = 0
    in_str = False
    esc = False
    end = None
    for k, ch in enumerate(text[jstart:], start=jstart):
        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue
        else:
            if ch == '"':
                in_str = True
                continue
            if ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0 and k > jstart:
                    end = k + 1
                    break

    if end is None:
        raise ValueError("initial JSON end not found")

    js = text[jstart:end]
    # QQ embeds JS objects (not strict JSON): replace common tokens.
    js = (
        js.replace(":undefined", ":null")
        .replace(",undefined", ",null")
        .replace("undefined,", "null,")
        .replace("undefined}", "null}")
        .replace("undefined]", "null]")
    )
    try:
        return json.loads(js)
    except json.JSONDecodeError as e:
        start = max(0, e.pos - 200)
        stop = min(len(js), e.pos + 200)
        snippet = js[start:stop]
        raise ValueError(
            f"JSON decode failed at pos={e.pos}: {e.msg}\n"
            f"...context...\n{snippet}\n..."
        ) from e


def main() -> None:
    p = find_largest_capture()
    text = p.read_text("utf-8", errors="ignore")
    obj = extract_initial_data(text)
    print("capture:", p.name)
    print("top_keys:", list(obj.keys()))
    for k, v in obj.items():
        if isinstance(v, list):
            print(f"{k}: list {len(v)}")
        elif isinstance(v, dict):
            print(f"{k}: dict {len(v)}")
        else:
            print(f"{k}: {type(v).__name__}")


if __name__ == "__main__":
    main()

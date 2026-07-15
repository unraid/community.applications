#!/usr/bin/env python3
"""Create a deterministic root-owned XZ-compressed tar archive."""

from __future__ import annotations

import os
from pathlib import Path
import sys
import tarfile


def normalized_info(tar: tarfile.TarFile, path: Path, name: str, mtime: int) -> tarfile.TarInfo:
    info = tar.gettarinfo(str(path), arcname=name)
    info.uid = 0
    info.gid = 0
    info.uname = "root"
    info.gname = "root"
    info.mtime = mtime
    info.mode = 0o755
    info.pax_headers = {}
    return info


def add_tree(tar: tarfile.TarFile, root: Path, relative: Path, mtime: int) -> None:
    path = root / relative
    name = f"./{relative.as_posix()}"
    info = normalized_info(tar, path, name, mtime)

    if info.isfile():
        with path.open("rb") as source:
            tar.addfile(info, source)
        return

    tar.addfile(info)
    if info.isdir():
        for child in sorted(path.iterdir(), key=lambda item: os.fsencode(item.name)):
            add_tree(tar, root, relative / child.name, mtime)


def main() -> int:
    if len(sys.argv) != 4:
        raise SystemExit("usage: create-reproducible-tar.py <source-dir> <archive.txz> <unix-mtime>")

    source = Path(sys.argv[1]).resolve()
    destination = Path(sys.argv[2]).resolve()
    mtime = int(sys.argv[3])
    if not source.is_dir():
        raise SystemExit(f"source directory does not exist: {source}")

    destination.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(destination, mode="w:xz", format=tarfile.GNU_FORMAT, preset=6) as tar:
        root_info = tarfile.TarInfo("./")
        root_info.type = tarfile.DIRTYPE
        root_info.uid = 0
        root_info.gid = 0
        root_info.uname = "root"
        root_info.gname = "root"
        root_info.mode = 0o755
        root_info.mtime = mtime
        tar.addfile(root_info)
        for child in sorted(source.iterdir(), key=lambda item: os.fsencode(item.name)):
            add_tree(tar, source, Path(child.name), mtime)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

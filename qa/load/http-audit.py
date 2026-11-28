"""Isolated demo HTTP audit: report latency and bytes; reject failed responses."""
import concurrent.futures
import json
import math
import os
import time
import sys
import urllib.request
from pathlib import Path
from urllib.parse import urlsplit

base = os.environ.get("E2E_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
assert urlsplit(base).hostname in ("127.0.0.1", "localhost"), "CI loopback only"
plan = json.loads(Path("var/catalog-plans.json").read_text())
scale = int(os.environ.get("SCALE_TARGET", "0"))
if scale:
    assert plan["products"] == scale, "Wrong catalog size"
routes = ["/", "/catalog", plan["product_path"], "/cart", "/catalog?q=" + ("scaleitem" if scale else "test")]
assert all(route.startswith("/") and not route.startswith("//") for route in routes)

def probe(route):
    start = time.perf_counter()
    try:
        with urllib.request.urlopen(base + route, timeout=30) as response:
            assert response.status == 200
            assert urlsplit(response.geturl()).hostname == urlsplit(base).hostname
            size = len(response.read())
    except Exception as exc:
        print(f'HTTP audit failed: route={route}, elapsed_ms={(time.perf_counter()-start)*1000:.1f}, error={exc}', file=sys.stderr, flush=True)
        raise
    return {"ms": (time.perf_counter() - start) * 1000, "bytes": size}

def summarize(samples):
    values = sorted(sample["ms"] for sample in samples)
    return {"samples": len(values), "p50_ms": round(values[math.ceil(len(values)*.50)-1], 2),
            "p95_ms": round(values[math.ceil(len(values)*.95)-1], 2),
            "p99_ms": round(values[math.ceil(len(values)*.99)-1], 2),
            "bytes": samples[-1]["bytes"]}

report = {"fixture_products": plan["products"], "sequential": {}, "concurrent": {}}
for route in routes:
    print(f"HTTP sequential: {route}", file=sys.stderr, flush=True)
    report["sequential"][route] = {"first": probe(route), "repeated": summarize([probe(route) for _ in range(20)])}
    print(json.dumps(report), file=sys.stderr, flush=True)
for workers in ((4, 8, 16) if scale else (4, 8)):
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
        results = list(executor.map(probe, routes * 12))
    report["concurrent"][str(workers)] = {**summarize(results), "rps": round(len(results)/(time.perf_counter()-start), 2)}
print(json.dumps(report, indent=2))

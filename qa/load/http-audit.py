"""Isolated demo HTTP audit: report latency and bytes; reject failed responses."""
import concurrent.futures
import json
import math
import os
import time
import urllib.request
from pathlib import Path
from urllib.parse import urlsplit

base = os.environ.get("E2E_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
assert urlsplit(base).hostname in ("127.0.0.1", "localhost"), "CI loopback only"
plan = json.loads(Path("var/catalog-plans.json").read_text())
routes = ["/", "/catalog", plan["product_path"], "/cart", "/catalog?q=test"]
assert all(route.startswith("/") and not route.startswith("//") for route in routes)

def probe(route):
    start = time.perf_counter()
    with urllib.request.urlopen(base + route, timeout=30) as response:
        assert response.status == 200
        assert urlsplit(response.geturl()).hostname == urlsplit(base).hostname
        size = len(response.read())
    return {"ms": (time.perf_counter() - start) * 1000, "bytes": size}

def summarize(samples):
    values = sorted(sample["ms"] for sample in samples)
    return {"samples": len(values), "p50_ms": round(values[math.ceil(len(values)*.50)-1], 2),
            "p95_ms": round(values[math.ceil(len(values)*.95)-1], 2),
            "p99_ms": round(values[math.ceil(len(values)*.99)-1], 2),
            "bytes": samples[-1]["bytes"]}

report = {"fixture_products": plan["products"], "sequential": {}, "concurrent": {}}
for route in routes:
    report["sequential"][route] = {"first": probe(route), "repeated": summarize([probe(route) for _ in range(20)])}
for workers in (4, 8):
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
        results = list(executor.map(probe, routes * 12))
    report["concurrent"][str(workers)] = {**summarize(results), "rps": round(len(results)/(time.perf_counter()-start), 2)}
print(json.dumps(report, indent=2))

# Benchmark

Compare Joojoo against Nginx using Apache Bench.

## Usage

```bash
# From project root
./benchmark/benchmark_ab.sh

# requests, concurrency
./benchmark/benchmark_ab.sh 5000 50
```

What the script does:

- Starts benchmark containers (Joojoo + Nginx) if needed
- Runs `ab -k` against both
- Prints requests/sec, latency, failed requests, and relative speed
- Always stops and removes benchmark containers after the run

Ports used:

- Joojoo: `http://127.0.0.1:8347/index.html`
- Nginx: `http://127.0.0.1:8591/index.html`

Requirements:

- Docker (with compose)
- Apache Bench (`ab`)

#!/usr/bin/env bash
ab -n 1000 -c 10 -k http://127.0.0.1:8000/sample-website/index.html
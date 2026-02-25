# Joojoo | جوجو

**A Tiny Web Server :baby_chick: for Small Tasks**

## The Story Behind joojoo

The web server that serves the TP-Link TD-8811 modem settings in my room was called `micro_httpd`. Out of curiosity, I searched for it and found this [repository](https://github.com/socram8888/micro_httpd). The code caught my attention, and I thought it would be a great learning experience to build a simple web server from scratch—similar to `micro_httpd`—to explore new computer science concepts and share what I learn with others.

## A Simple Web Server with PHP

In this repository, I aim to build a basic web server step by step following the rules of [Hypertext Transfer Protocol -- HTTP/1.1](https://datatracker.ietf.org/doc/html/rfc2616).  
Since I primarily work with PHP these days, I decided to implement it first in PHP 8 and later rewrite it in another language (probably C++ or Go).

## How to Run This Web Server?

To run it in a Docker environment, simply use the following command:

```
docker run --name joojoo --init --rm \
          -v YOUR_WEB_DIR:/html \
          -p 80:8000 \
          ohmydevops/joojoo
```

Replace `YOUR_WEB_DIR` with the path to your website's root directory.  
For example, if your static website is located at `/home/user/website`, run:

```
docker run --name joojoo --init --rm \
          -v /home/user/website:/html \
          -p 80:8000 \
          ohmydevops/joojoo
```

Then, open your browser to view your website.

## Roadmap  

- [x] Serve basic web files (HTML, CSS, JS)  
- [x] Serve basic static files (images, videos, sounds)  
- [x] Support 200 status code  
- [x] Support 404 status code  
- [x] Support GET method  
- [x] Handle requests in blocking mode  
- [x] Dockerized (uploaded to Docker Hub)  
- [x] Configurable root directory via ENV  
- [x] Support concurrency  
- [x] Support [Common Log Format](https://en.wikipedia.org/wiki/Common_Log_Format)  
- [x] Use simple worker-mode for handle requests [Prefork in Apache](https://httpd.apache.org/docs/2.4/mod/prefork.html)
- [x] Support Keep-alive header  

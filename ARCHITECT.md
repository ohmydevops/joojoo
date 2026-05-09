# Joojoo Architecture

This document describes how the project works today.

## 1) Server Startup And Running

This chart shows what happens when you start `php server.php`.

```mermaid
flowchart TD
    A[Start php server.php] --> B[Load config and constants]
    B --> C[Create listening socket 0.0.0.0:8000]
    C --> D{Socket created?}
    D -->|No| E[Log error and exit]
    D -->|Yes| F[Fork WORKER_COUNT processes]
    F --> G[Parent stores worker PIDs]
    F --> H[Child enters worker loop]
    H --> I[accept client]
    I --> J[handle client connection]
    J --> I
    G --> K[Parent waits on workers]
```

## 2) Request Processing (Assuming Server Is Already Running)

This chart starts at the moment a worker accepts a client connection.

```mermaid
flowchart TD
    A[Worker accepted client] --> B[Read request until CRLF CRLF]
    B --> C{Request read success?}
    C -->|No| D[Close client socket]
    C -->|Yes| E[Parse first line and headers]
    E --> F[Resolve request path]
    F --> G{File exists and readable?}
    G -->|Yes| H[Build 200 response with file body]
    G -->|No| I[Build 404 HTML response]
    H --> J[Set Content-Type and Content-Length]
    I --> J
    J --> K{Keep-Alive requested and limit not reached?}
    K -->|Yes| L[Set Connection Keep-Alive and send]
    K -->|No| M[Set Connection close and send]
    L --> N[Log request]
    N --> B
    M --> O[Log request]
    O --> D
```

## Main Request Path

1. The main process binds to `0.0.0.0:8000`.
2. It forks multiple workers (`WORKER_COUNT = cores x 2`).
3. Each worker accepts a client and reads until `\r\n\r\n`.
4. The server parses headers and request target path.
5. If file exists, it returns `200 OK`; otherwise a built-in `404 Not Found` page.
6. Response includes `Content-Type`, `Content-Length`, and connection headers.
7. Keep-Alive loop continues until timeout or max requests.

## Process Model

- One listening socket is created before forking.
- Worker children share the listening socket and compete on `socket_accept`.
- Parent process waits for worker PIDs.

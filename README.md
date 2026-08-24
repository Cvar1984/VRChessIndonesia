# ♚ VRChess Indonesia — API Service Documentation

Welcome to the official API documentation for **VRChess Indonesia**. This service powers the chess rating system, match management, player statistics, API access token control, and real-time Stockfish 18 chess engine analysis.

---

## 📋 Table of Contents

1. [Authentication](#-authentication)
2. [Base Response Format](#-base-response-format)
3. [Player & Ranking Endpoints](#-player--ranking-endpoints)
4. [Match Management Endpoints](#-match-management-endpoints)
5. [Stockfish Engine Analysis API](#-stockfish-engine-analysis-api)
6. [Error Handling & Status Codes](#-error-handling--status-codes)

---

## 🔑 Authentication

The API uses Token-based authentication for mutation endpoints (creating, editing, and deleting records). Read endpoints (retrieving players, matches, stats, and rankings) are public and do not require authentication. 

You can pass your API Token to protected endpoints using any of the following methods:

- **HTTP Header (`X-API-Token`)**:
  ```http
  X-API-Token: your_api_token_here
  ```
- **HTTP Header (`Authorization: Bearer`)**:
  ```http
  Authorization: Bearer your_api_token_here
  ```
- **Query Parameter**:
  ```http
  GET /index.php?players=1&token=your_api_token_here
  ```

---

## 📡 Base Response Format

All API endpoints return JSON formatted responses:

#### Success Response Example
```json
{
  "success": true,
  "data": { ... }
}
```

#### Error Response Example
```json
{
  "success": false,
  "error": "Detailed error message here"
}
```

## 🏆 Player & Ranking Endpoints

### 1. Get All Players & Ratings
Retrieve complete list of registered players with rating details.

- **Method**: `GET`
- **URL**: `/index.php?players=1`
- **Response**:
  ```json
  {
    "success": true,
    "count": 12,
    "players": [
      {
        "id": 1,
        "username": "Alice",
        "rating": 520,
        "games_played": 15,
        "wins": 10,
        "draws": 2,
        "losses": 3
      }
    ]
  }
  ```

### 2. Get Player Rankings
Retrieve leaderboard ranked by current rating.

- **Method**: `GET`
- **URL**: `/index.php?rankings=1`

### 3. Get Single Player Statistics
Retrieve detailed match statistics for a specific player.

- **Method**: `GET`
- **URL**: `/index.php?player-stats=1&username=Alice`
- **Response**:
  ```json
  {
    "success": true,
    "stats": {
      "username": "Alice",
      "rating": 520,
      "total_matches": 15,
      "wins": 10,
      "draws": 2,
      "losses": 3,
      "win_rate": 66.67
    }
  }
  ```

### 4. Edit Player Details
Update rating or username of an existing player.

- **Method**: `PATCH`
- **URL**: `/index.php?player=Alice`
- **Headers**: `X-API-Token: <token>`
- **Body** (`application/json`):
  ```json
  {
    "rating": 550,
    "username": "Alice_New"
  }
  ```

### 5. Delete Player
Remove a player profile.

- **Method**: `DELETE`
- **URL**: `/index.php?player=Alice`
- **Headers**: `X-API-Token: <token>`

---

## ⚔ Match Management Endpoints

### 1. Get Match History
Retrieve all recorded matches.

- **Method**: `GET`
- **URL**: `/index.php?matches=1`

### 2. Get Valid / Invalidated Matches
Filter matches by validation status.

- **Method**: `GET`
- **URL**: `/index.php?valid-matches=1`
- **URL**: `/index.php?invalid-matches=1`

### 3. Submit New Match
Record a match result and automatically recalculate player ratings.

- **Method**: `POST`
- **URL**: `/index.php?play=1`
- **Headers**: `X-API-Token: <token>`
- **Body** (`application/json`):
  ```json
  {
    "white": "Alice",
    "black": "Bob",
    "result": "1",
    "url": "https://chessigma.com/games/..."
  }
  ```
  *Note on `result`: `1` or `1-0` (White Win), `0` or `1/2-1/2` (Draw), `-1` or `0-1` (Black Win).*

### 4. Invalidate Match
Invalidate a match (reverts rating impact without permanently deleting history).

- **Method**: `PUT`
- **URL**: `/index.php?match=42&invalidate=1`
- **Headers**: `X-API-Token: <token>`

### 5. Revalidate Match
Re-apply an invalidated match and recalculate player ratings.

- **Method**: `PUT`
- **URL**: `/index.php?match=42&revalidate=1`
- **Headers**: `X-API-Token: <token>`

### 6. Edit Match Details
Update match result or analysis URL.

- **Method**: `PATCH`
- **URL**: `/index.php?match=42`
- **Headers**: `X-API-Token: <token>`
- **Body** (`application/json`):
  ```json
  {
    "result": "0",
    "analysis_url": "https://chessigma.com/games/updated"
  }
  ```

### 7. Delete Match
Permanently delete a match record.

- **Method**: `DELETE`
- **URL**: `/index.php?match=42`
- **Headers**: `X-API-Token: <token>`

---

## 🧠 Stockfish Engine Analysis API

Stockfish engine analysis services are exposed via `stockfish.php`.

### 1. Single FEN Position Analysis
Evaluate a single board position using Stockfish.

- **Method**: `GET` or `POST`
- **URL**: `/stockfish.php`
- **Query / Body Parameters**:
  - `fen` *(string, required)*: Standard FEN position string.
  - `depth` *(int, optional)*: Analysis search depth (default: 15, max: 30).
  - `movetime` *(int, optional)*: Search time in milliseconds (100–10000).

- **Request Example**:
  ```http
  POST /stockfish.php
  Content-Type: application/json

  {
    "fen": "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1",
    "depth": 15
  }
  ```

- **Response Example**:
  ```json
  {
    "bestmove": "e7e5",
    "score": 0.25,
    "score_type": "cp",
    "depth": 15,
    "pv": ["e7e5", "g1f3", "b8c6", "f1b5"]
  }
  ```

### 2. Batch FEN Analysis (Full PGN Game)
Evaluate an entire sequence of game positions.

- **Method**: `POST`
- **URL**: `/stockfish.php`
- **Body Parameters**:
  - `fens` *(array of FEN strings, required, max 120)*: List of FEN positions.
  - `depth` *(int, optional)*: Depth per position (1–20, default: 12).

- **Request Example**:
  ```json
  {
    "fens": [
      "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
      "rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq e3 0 1"
    ],
    "depth": 12
  }
  ```

- **Response Example**:
  ```json
  {
    "success": true,
    "count": 2,
    "depth": 12,
    "positions": [
      {
        "fen": "rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1",
        "move_index": 0,
        "score": 0.18,
        "score_cp": 18,
        "score_type": "cp",
        "bestmove": "e2e4",
        "pv": ["e2e4", "e7e5"],
        "depth": 12
      }
    ]
  }
  ```

---

## ⚠️ Error Handling & Status Codes

The API utilizes standard HTTP status codes:

| Code | Status | Meaning |
| :--- | :--- | :--- |
| `200` | **OK** | Request succeeded. |
| `204` | **No Content** | CORS preflight succeeded. |
| `400` | **Bad Request** | Invalid input parameters or failed validation. |
| `401` | **Unauthorized** | Missing or invalid API Token / Admin Authentication. |
| `404` | **Not Found** | Player, Match, Token, or Admin user not found. |
| `500` | **Internal Server Error** | Server-side execution exception. |

---
*VRChess Indonesia © 2026 — Chess Rating & Management Platform*

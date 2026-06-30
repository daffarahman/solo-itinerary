# Solo Itinerary — Crowd Avoidance Scheduler

**Solo Itinerary** is an intelligent, web-based tourist itinerary planner for Surakarta (Solo), Indonesia. Using a **Hill Climbing** optimization algorithm, the application helps travelers construct schedules that maximize the number of visited places of interest (POIs) while minimizing time spent in crowded conditions.

---

## Key Features

* **Crowd Avoidance Engine**: Utilizes historical crowd density scores (hourly distributions per day) to schedule visits during off-peak hours.
* **Hill Climbing Optimization**: Rapidly searches the permutation space of POI combinations, iteratively swapping slots to find a local optimum with the lowest overall crowd score.
* **Interactive Map View**: Integrates Leaflet Maps to display tourist locations and travel flows between scheduled destinations.
* **Dynamic Timeline**: Visualizes the planned trip daily schedule including travel time buffers (30 mins) and average dwell times.
* **Dataset Management**: Reads tourist spots and crowd forecasts directly from tabular CSV datasets.
* **Dockerized Execution**: Ready-to-go setup running on Apache and PHP 8.3.

---

## Technology Stack

* **Backend**: PHP 8.3 (Session management, CSV parsing, Optimization Engine)
* **Frontend**: HTML5, Vanilla JavaScript, CSS (via Tailwind CSS CDN)
* **Map Library**: Leaflet Maps (OpenStreetMap tiles)
* **Containerization**: Docker & Docker Compose

---

## Dataset Structure

The application's logic is powered by three CSV datasets located in the `dataset/` directory:

1. **`poi.csv`**: Attributes of successfully parsed POIs.
   * *Fields*: `poi_id`, `input_name`, `matched_name`, `matched_address`, `category`, `venue_type`, `latitude`, `longitude`, `duration_min`, `dwell_time_min`, `dwell_time_max`, `dwell_time_avg`, `rating`, `reviews`, `source`, `retrieved_at`
2. **`crowd_score.csv`**: Historical hourly crowd levels.
   * *Fields*: `poi_id`, `poi_name`, `day_int` (0-6 representing Mon-Sun), `day_name`, `hour` (0-23), `crowd_score` (0-100 scale), `crowd_level` (low/medium/high), `source`, `retrieved_at`
3. **`failed_poi.csv`**: Contains POIs that failed to retrieve crowd forecasting data (useful for logs/error boundary testing).

---

## Optimization Algorithm (Hill Climbing)

The scheduling system models the itinerary as a sequence of POI visits and optimizes it via a Local Search method:
1. **Initial State**: Generates a random or greedy permutation of the selected POIs.
2. **Decoding**: Sequentially schedules POIs on the earliest day they fit, inserting travel buffers (30 minutes) and average dwell times within the user-defined daily time window.
3. **Evaluation**: Calculates a cost function:
   $$\text{Cost} = (\text{Unvisited POIs} \times 1000) + \text{Average Crowd Density}$$
4. **Neighbor Generation**: Swaps the sequence/order of visited POIs.
5. **Move**: Moves to the neighbor state if it results in a lower cost.
6. **Termination**: Stops when no neighboring sequence improves the cost (reaches a local optimum).

---

## Getting Started

### Prerequisites

Make sure you have [Docker](https://www.docker.com/) and [Docker Compose](https://docs.docker.com/compose/) installed on your machine.

### Launching the Application

1. Spin up the application container:
   ```bash
   docker compose up -d
   ```
2. Open your web browser and navigate to:
   ```
   http://localhost:9922
   ```
3. Stop the container:
   ```bash
   docker compose down
   ```

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## NaraboxTV File Server Worker

Dedicated Laravel worker for media processing (transcode, probe, sync). Runs on a separate VPS under Coolify; communicates with CDN and Portal via API.

### Local development (Mac)

1. **Install dependencies**
   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/file-server-worker
   composer install
   ```

2. **Environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Set `QUEUE_CONNECTION=redis`, `REDIS_HOST=127.0.0.1` (or your Redis). Use `REDIS_CLIENT=predis` if the redis PHP extension is not installed.

3. **Redis**
   ```bash
   # If using Homebrew
   brew install redis && brew services start redis
   ```

4. **Migrations (optional)**
   ```bash
   php artisan migrate
   ```

5. **Run Horizon**
   ```bash
   php artisan horizon
   ```
   Or run the default queue worker: `php artisan queue:work redis --queue=transcode,probe,sync`.

6. **Test FFmpeg**
   ```bash
   php artisan ffmpeg:test
   ```
   Requires `ffmpeg` and `ffprobe` on PATH (e.g. `brew install ffmpeg`).

7. **Dispatch test job**
   ```bash
   php artisan tinker
   >>> App\Jobs\Transcode\RunFfmpegHealthcheckJob::dispatch();
   ```
   Then check Horizon dashboard at `http://localhost:8000/horizon` (if you run `php artisan serve` in another terminal) or check logs.

### Before adding the project to Coolify (push to GitHub first)

1. **Push this project to GitHub** so Coolify can clone it:
   - Repo: [https://github.com/smog-grafton/naraboxtvworker](https://github.com/smog-grafton/naraboxtvworker)
   - From your machine (in the project root):
     ```bash
     git init
     git remote add origin https://github.com/smog-grafton/naraboxtvworker.git
     git add .
     git commit -m "Initial worker: Laravel, Horizon, FFmpeg, Dockerfile"
     git branch -M main
     git push -u origin main
     ```
   - Use your GitHub credentials (PAT or SSH). If the repo already has content (e.g. LICENSE), fetch and merge first:
     ```bash
     git fetch origin
     git pull origin main --allow-unrelated-histories
     # Resolve any conflicts, then:
     git push -u origin main
     ```

2. **Ensure the repo contains**:
   - `Dockerfile` (in project root)
   - `.env.example` (Coolify will use this as a template; do not commit `.env`)
   - `composer.json` / `composer.lock` so the image can run `composer install`

3. **Redis**: Have a Redis instance ready (Coolify Redis service, or external). You will need `REDIS_HOST`, `REDIS_PASSWORD` (if any), and `REDIS_PORT` for the worker.

---

### Adding the project to Coolify

1. In Coolify, go to **Projects** → your project → **Add Resource** → **Application**.
2. **Source**: Choose **GitHub** (or **Public Repository**), then:
   - Repository URL: `https://github.com/smog-grafton/naraboxtvworker`
   - Branch: `main`
   - (If using GitHub integration, connect the repo and select it.)
3. **Build Pack**: Choose **Dockerfile**.
   - Dockerfile location: leave default (root) or set to `./Dockerfile` if prompted.
4. **General**:
   - Application name: e.g. `naraboxtv-worker`.
   - (Optional) Base directory: leave empty if the repo root is the app.
5. **Environment Variables**: Add from `.env.example`. At minimum set:
   - `APP_KEY` — generate one: `php artisan key:generate --show` (run locally) and paste.
   - `APP_ENV=production`
   - `QUEUE_CONNECTION=redis`
   - `REDIS_HOST` — Redis service hostname in Coolify (e.g. `redis`) or your Redis server IP.
   - `REDIS_PASSWORD` — if your Redis has a password.
   - `REDIS_PORT=6379`
   - `REDIS_CLIENT=predis` (or `phpredis` if the Docker image has the extension).
   - Optionally: `CDN_API_BASE_URL`, `CDN_API_TOKEN`, `PORTAL_API_BASE_URL`, ``PORTAL_API_TOKEN`` when you integrate with CDN/Portal.
6. **Deploy**: Start the deployment. Coolify will build the Dockerfile and run the default command (`php artisan horizon`).

---

### Coolify Redis: Where to find connection details

In Coolify, open your **Redis** resource. You’ll see:

- **Redis URL (internal)** — use this for apps (like the worker) that run **inside** Coolify (same network).  
  Example format:  
  `rediss://default:PASSWORD@CONTAINER_NAME:6380/0`  
  - **CONTAINER_NAME** = internal hostname (e.g. `ugow8kk0g8cog0ck4kg0c000`).  
  - **6380** = internal port (Coolify often uses 6380 for TLS; 6379 may be the public port).  
  - **PASSWORD** = the Redis password.

- **General** tab: **Username** (often `default`), **Password** — copy the password from here for `REDIS_PASSWORD`.

- **Public Port** (e.g. 6379) is for external access; the worker should use the **internal** host and port from the internal URL.

**Do not use `127.0.0.1`** for the worker; the worker container has no Redis on localhost.

---

### Connecting the worker to Coolify Redis

Set these **Environment Variables** on the **worker application** in Coolify (not on the Redis resource):

| Variable | Where to get it | Example |
|----------|-----------------|---------|
| **REDIS_HOST** | Hostname from **Redis URL (internal)** (the part between `@` and `:`) | `ugow8kk0g8cog0ck4kg0c000` |
| **REDIS_PORT** | Port from **Redis URL (internal)** (after the hostname, before `/`) | `6380` |
| **REDIS_PASSWORD** | **General** → **Password** in the Redis resource | (paste the value) |
| **REDIS_CLIENT** | Use `phpredis` (Docker image has the extension) | `phpredis` |

Optional: if you prefer a single URL, set **REDIS_URL** to the full **Redis URL (internal)** (e.g. `rediss://default:YOUR_PASSWORD@ugow8kk0g8cog0ck4kg0c000:6380/0`). Laravel will use it for the default Redis connection. If you set `REDIS_URL`, you can omit `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` for that connection. The `rediss://` scheme is Redis over TLS (Coolify’s internal URL often uses this).

After saving env vars, **Redeploy** or **Restart** the worker so it picks up the new values.

---

### Fix: "Connection refused [tcp://127.0.0.1:6379]" and (8x restarts)

This means the worker is still using Redis at `127.0.0.1`. Fix it by:

1. Setting **REDIS_HOST** to the **internal** Redis hostname (the container name from **Redis URL (internal)**), e.g. `ugow8kk0g8cog0ck4kg0c000`.
2. Setting **REDIS_PORT** to the port from the internal URL (e.g. **6380**, not the public 6379).
3. Setting **REDIS_PASSWORD** to the password from the Redis resource **General** tab.
4. Redeploying or restarting the worker.

---

### After the project has been imported and deployed

1. **Check build logs** in Coolify for the application. The build should:
   - Run `composer install --no-dev`
   - Finish without errors.
2. **Check runtime**:
   - Container should be running and the main process should be `php artisan horizon` (or the command you set).
   - In Coolify, open the application **Logs** tab to see Horizon/worker output.
3. **Verify Redis**: If jobs are not processing, confirm the worker can reach Redis (same Docker network as Redis, or correct `REDIS_HOST`/port/password).

---

### Testing the worker on Coolify

1. **Horizon dashboard** (optional): If you expose a port and run `php artisan serve` in the same container, you can view Horizon at `/horizon`. For a worker-only deployment you usually do not expose HTTP; use logs instead.
2. **Dispatch a test job** (Coolify Terminal or SSH)
   ```bash
   php artisan worker:dispatch-healthcheck
   ```
   (No tinker—avoids psysh "not allowed" in containers.)
3. **Check logs** in Coolify for the worker application. You should see the job run and a log line like `FFmpeg healthcheck job completed` with `ffmpeg` and `ffprobe` results.
4. **FFmpeg in container**: The Dockerfile installs `ffmpeg`; `php artisan ffmpeg:test` can be run via Coolify “Execute Command” (if available) to confirm FFmpeg is available inside the container.

### Next steps for full transcode pipeline

1. Add jobs that accept a CDN source ID or URL, download the file to `WORKER_TEMP_DIR`, run FFmpeg (faststart, HLS), then upload results to CDN (or shared storage) and notify CDN/Portal via API.
2. Have the CDN (or Portal) push jobs to Redis or a shared queue so the worker pulls transcode requests.
3. Add probe job for metadata extraction (ffprobe) and sync job for post-processing notifications.

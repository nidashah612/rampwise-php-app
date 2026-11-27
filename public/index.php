<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e($_SESSION['csrf']) ?>">
  <title>RampWise – Accessible Campus Route Planner</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <a class="skip" href="#results">Skip to results</a>
  <header class="site-header">
    <h1>RampWise</h1>
    <p>Step-free campus routes, checked before you go.</p>
    <button type="button" id="theme-toggle" class="btn ghost">Toggle theme</button>
  </header>

  <main class="layout">
    <aside class="sidebar">
      <form id="search-form" novalidate>
        <h2>Find a route</h2>
        <label for="from">Where are you?</label>
        <select id="from" name="from"><option value="">Choose…</option></select>
        <span class="err" data-err="from"></span>

        <label for="to">Where do you want to go?</label>
        <select id="to" name="to"><option value="">Choose…</option></select>
        <span class="err" data-err="to"></span>

        <fieldset>
          <legend>Your needs</legend>
          <label class="check"><input type="checkbox" name="step_free"> Step-free</label>
          <label class="check"><input type="checkbox" name="covered"> Covered</label>
          <label class="check"><input type="checkbox" name="avoid_steep"> Avoid steep slopes</label>
        </fieldset>

        <label for="pace">Walking pace</label>
        <select id="pace" name="pace">
          <option value="slow">Slow</option>
          <option value="normal" selected>Normal</option>
          <option value="fast">Fast</option>
        </select>

        <label for="sort">Sort by</label>
        <select id="sort" name="sort">
          <option value="time" selected>Fastest first</option>
          <option value="access">Most accessible first</option>
        </select>

        <label for="max_m">Max distance (m, optional)</label>
        <input id="max_m" name="max_m" type="number" min="10" max="2000" inputmode="numeric">
        <span class="err" data-err="max_m"></span>

        <button class="btn" type="submit">Find routes</button>
      </form>

      <form id="route-form" novalidate>
        <h2 id="route-form-title">Add a route</h2>
        <input type="hidden" name="id" id="rf-id">
        <label for="rf-from">From</label>
        <select id="rf-from" name="from_id"><option value="">Choose…</option></select>
        <span class="err" data-err="from_id"></span>

        <label for="rf-to">To</label>
        <select id="rf-to" name="to_id"><option value="">Choose…</option></select>
        <span class="err" data-err="to_id"></span>

        <label for="rf-distance">Distance (m)</label>
        <input id="rf-distance" name="distance_m" type="number" min="10" max="2000" required>
        <span class="err" data-err="distance_m"></span>

        <label for="rf-steps">Number of steps</label>
        <input id="rf-steps" name="steps" type="number" min="0" max="200" value="0">
        <span class="err" data-err="steps"></span>

        <label for="rf-slope">Slope</label>
        <select id="rf-slope" name="slope">
          <option value="flat">Flat</option>
          <option value="gentle">Gentle</option>
          <option value="steep">Steep</option>
        </select>
        <span class="err" data-err="slope"></span>

        <label class="check"><input type="checkbox" name="has_lift"> Lift available</label>
        <label class="check"><input type="checkbox" name="covered"> Covered</label>

        <div class="row">
          <button class="btn" type="submit" id="rf-submit">Save route</button>
          <button class="btn ghost" type="button" id="rf-cancel" hidden>Cancel edit</button>
        </div>
      </form>
    </aside>

    <section class="main" id="results" tabindex="-1">
      <h2>Routes</h2>
      <p id="status" class="status" role="status" aria-live="polite"></p>
      <p id="idle" class="state">Choose where you are and where you want to go.</p>
      <p id="loading" class="state" hidden>Loading routes…</p>
      <p id="empty" class="state" hidden>No routes match. Try removing a filter or raising the max distance.</p>
      <div id="error" class="state error" hidden>
        <p id="error-msg"></p>
        <button type="button" class="btn" id="retry">Try again</button>
      </div>
      <div id="list" class="cards"></div>
    </section>
  </main>

  <script src="app.js" defer></script>
</body>
</html>

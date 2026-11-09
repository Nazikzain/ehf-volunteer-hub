<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Certificate <?= e($c['reference']) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/css/app.css')) ?>"><link rel="stylesheet" href="<?= e(url('/assets/css/certificate.css')) ?>"></head>
<body class="cert-body"><main class="cert">
<img src="<?= e(url('/assets/img/ehf-logo.jpg')) ?>" alt="Ethnic Health Forum" class="cert-logo">
<p class="cert-kicker">Certificate of Volunteering</p>
<h1><?= e($c['full_name']) ?></h1>
<p>In recognition of <strong><?= e(minutes_to_hours($c['approved_minutes_at_issue'])) ?> hours</strong> of volunteering with Ethnic Health Forum.</p>
<p class="muted">Issued <?= e(local_dt($c['issued_at'], 'j F Y')) ?> · Reference <?= e($c['reference']) ?></p>
<p class="muted">Ethnic Health Forum · Registered charity no. <?= e(App\Services\Settings::get('org.charity_number', '')) ?></p>
<p class="no-print"><a href="<?= e(url('/certificates')) ?>">Back</a> · Use your browser's Print → "Save as PDF" to download.</p>
</main></body></html>

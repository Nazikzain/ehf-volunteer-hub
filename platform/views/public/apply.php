<?php use App\Core\Csrf; use App\Services\ApplicationService as A; $o = $old ?? []; ?>
<h1>Share your skills. Strengthen our community.</h1>
<p>Join Ethnic Health Forum as a volunteer. Tell us about your skills, interests and availability so we can explore suitable opportunities across our community projects.</p>
<div class="card">
<h2 class="section-title">Volunteer registration</h2>
<p class="hint">Fields marked * are required.</p>
<form method="post" action="<?= e(url('/apply')) ?>" novalidate>
  <?= Csrf::field() ?>
  <label for="full_name">Full name *</label>
  <input id="full_name" name="full_name" autocomplete="name" maxlength="150" required value="<?= e($o['full_name'] ?? '') ?>">
  <label for="email">Email address *</label>
  <input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= e($o['email'] ?? '') ?>">
  <div class="grid grid-2">
    <div><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="40" value="<?= e($o['phone'] ?? '') ?>"></div>
    <div><label for="postcode">Postcode</label><input id="postcode" name="postcode" autocomplete="postal-code" maxlength="12" placeholder="e.g. M1 1AE" value="<?= e($o['postcode'] ?? '') ?>"></div>
  </div>
  <label for="expertise">Skills or area of expertise *</label>
  <select id="expertise" name="expertise" required><option value="">Select an area</option>
    <?php foreach (A::EXPERTISE as $x): ?><option<?= ($o['expertise'] ?? '') === $x ? ' selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
  <label for="availability_type">Availability *</label>
  <select id="availability_type" name="availability_type" required><option value="">Select availability</option>
    <option value="specific_days"<?= ($o['availability_type'] ?? '') === 'specific_days' ? ' selected' : '' ?>>Specific days</option>
    <option value="flexible"<?= ($o['availability_type'] ?? '') === 'flexible' ? ' selected' : '' ?>>Flexible</option></select>
  <fieldset id="days-field" class="checks plain"><legend><strong>Which days are you available? *</strong></legend>
    <?php foreach (A::DAYS as $n => $d): ?><label><input type="checkbox" name="available_days[]" value="<?= $n ?>"<?= in_array((string) $n, (array) ($o['available_days'] ?? []), true) ? ' checked' : '' ?>> <?= e($d) ?></label><?php endforeach; ?>
  </fieldset>
  <label for="referral_source">How did you hear about us?</label>
  <select id="referral_source" name="referral_source"><option value="">Select an option</option>
    <?php foreach (A::REFERRAL as $x): ?><option<?= ($o['referral_source'] ?? '') === $x ? ' selected' : '' ?>><?= e($x) ?></option><?php endforeach; ?></select>
  <label for="motivation">Why would you like to volunteer?</label>
  <textarea id="motivation" name="motivation" maxlength="2000"><?= e($o['motivation'] ?? '') ?></textarea>
  <div class="hp" aria-hidden="true"><label for="website">Leave this field empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
  <label class="checks consent"><input type="checkbox" name="privacy_consent" value="1" required>
    <span><?= e($consent) ?> (<a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener">read the privacy notice</a>) *</span></label>
  <div class="actions"><button class="btn" type="submit">Submit volunteer application</button></div>
</form>
</div>
<p class="muted">Already a volunteer? <a href="<?= e(url('/login')) ?>">Sign in</a>.</p>

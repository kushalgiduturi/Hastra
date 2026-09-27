<?php
// Hastra Labs — Module 3: Student Syllabus Accelerator & Video Tutor.
// Documents are parsed in the browser (assets/js/doc-extract.js); only topic
// names reach the server, for the view-ranked video lookup.
require __DIR__ . '/_boot.php';
labs_session_start();
$profile = labs_current_profile($conn);
labs_head('Syllabus Accelerator & Video Tutor', 'syllabus', $profile);
?>
<section class="lx-hero lx-hero--compact">
  <p class="lx-eyebrow"><span class="lx-dot"></span> Module 03 · study smarter, on a schedule</p>
  <h1 class="lx-h1">Student Syllabus Mastery &amp; <em>Video Tutor</em></h1>
  <p class="lx-lead">Drop in your syllabi. Hastra pulls out every unit and topic, pairs each topic with its most-viewed
    tutorial, and turns your deadline into a daily pace you can actually follow. Skip a video you don't like and the next
    best slides in; it won't come back.</p>
</section>

<section class="lx-pane lx-noprint" aria-labelledby="syl-up-h">
  <h2 id="syl-up-h" class="lx-h2">1 · Syllabus documents &amp; target</h2>
  <div class="lx-grid-2">
    <div>
      <div class="lx-drop" id="syl-drop" tabindex="0" role="button" aria-describedby="syl-drop-hint">
        <input type="file" id="syl-files" multiple accept=".pdf,.docx,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain" tabindex="-1" aria-label="Choose syllabus documents">
        <b>Drop PDF, DOCX or TXT syllabi</b><small id="syl-drop-hint">Several at once is fine · parsed in your browser · up to 25 MB each</small>
      </div>
      <div class="lx-row lx-mt" id="syl-files-chips"></div>
    </div>
    <div>
      <div class="lx-grid-3">
        <div><label class="lx-label" for="syl-target">Target date</label><input type="date" id="syl-target" class="lx-input"></div>
        <div><label class="lx-label" for="syl-hours">Daily hours</label><input type="number" id="syl-hours" class="lx-input" min="0.5" max="16" step="0.5" value="2.5"></div>
        <div><label class="lx-label" for="syl-mult">Practice ×</label><input type="number" id="syl-mult" class="lx-input" min="0.25" max="4" step="0.25" value="1"></div>
      </div>
      <p class="lx-help">Required hours = topics × average video length × practice multiplier. Leave the multiplier at 1 to plan on
        video time alone; 1.5 is more realistic if you take notes and practise.</p>
      <div class="lx-row lx-mt">
        <button type="button" class="lx-btn btn-beam" id="syl-run">Extract syllabus &amp; launch tutor</button>
        <button type="button" class="lx-btn lx-btn--ghost" id="syl-demo">Try the SOC demo syllabus</button>
      </div>
    </div>
  </div>
  <div id="syl-msg" class="lx-mt" role="status"></div>
</section>

<section class="lx-pane lx-mt lx-noprint" id="syl-library" hidden aria-label="Your syllabi">
  <div class="lx-row lx-row--between">
    <h2 class="lx-h3" style="margin:0">Your syllabi</h2>
    <span class="lx-muted" id="syl-sync" aria-live="polite"></span>
  </div>
  <div class="lx-library lx-mt" id="syl-lib-list"></div>
  <div class="lx-row lx-mt">
    <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="syl-apply">Apply new target to this syllabus</button>
    <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="syl-delete">Remove this syllabus</button>
    <?php if (!$profile): ?><a class="lx-btn lx-btn--ghost lx-btn--sm" href="<?= labs_base() ?>auth">Sync across devices</a><?php endif; ?>
  </div>
</section>

<section class="lx-pane lx-mt" id="syl-banner" hidden aria-live="polite" aria-label="Pacing and motivation"></section>
<div id="syl-tree"></div>
<?php labs_foot(['labs-common', 'syllabus-engine']); ?>

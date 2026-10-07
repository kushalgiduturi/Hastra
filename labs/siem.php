<?php
// Hastra Labs — Module 2: SIEM / Threat Intelligence & SOC Simulator.
// Four browser-side tools (assets/js/siem-lab.js). Feeds and logs pasted
// here are processed locally and never sent to the server.
require __DIR__ . '/_boot.php';
labs_session_start();
$profile = labs_current_profile($conn);
labs_head('SIEM / Threat Intel & SOC Lab', 'siem', $profile);
?>
<section class="lx-hero lx-hero--compact">
  <p class="lx-eyebrow"><span class="lx-dot"></span> Module 02 · SOC engineering, hands-on</p>
  <h1 class="lx-h1">SIEM Engineering &amp; <em>Threat Intelligence</em> Simulator</h1>
  <p class="lx-lead">Normalize real feed formats, score behavioural risk, size a pipeline for millions of events per second,
    and stitch three clouds into one incident timeline. Everything you paste stays in this tab.</p>
</section>

<div class="lx-subnav" role="tablist" aria-label="SOC tools" id="sx-tabs">
  <button role="tab" aria-selected="true"  aria-controls="sl-p-ti"   id="sl-t-ti"   data-hash="threat-feed">A · STIX / TAXII normalizer</button>
  <button role="tab" aria-selected="false" aria-controls="sl-p-ueba" id="sl-t-ueba" data-hash="ueba">B · UEBA risk score</button>
  <button role="tab" aria-selected="false" aria-controls="sl-p-eps"  id="sl-t-eps"  data-hash="scaling">C · 15M EPS scaling</button>
  <button role="tab" aria-selected="false" aria-controls="sl-p-pipe" id="sl-t-pipe" data-hash="pipeline">D · Multi-cloud pipeline</button>
</div>

<!-- ═══ A · Threat feed normalizer ══════════════════════════════════════ -->
<section role="tabpanel" id="sl-p-ti" aria-labelledby="sl-t-ti">
  <div class="lx-grid-2">
    <div class="lx-pane">
      <h2 class="lx-h2">Ingest a feed</h2>
      <p class="lx-muted">Accepts a STIX 2.1 bundle, a TAXII 2.1 envelope (<code>{"objects": […]}</code>), an OpenCTI export, a MISP
        event (JSON), or a plain list of IOCs, one per line. Defanged values like <code>hxxp://evil[.]example</code> are refanged.</p>
      <label class="lx-label lx-mt" for="ti-in">Feed</label>
      <textarea id="ti-in" class="lx-textarea" rows="12" spellcheck="false" placeholder='{"type": "bundle", "objects": [ … ]}'></textarea>
      <div class="lx-row lx-mt">
        <button type="button" class="lx-btn btn-beam" id="ti-add">Normalize &amp; add to store</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-sample="stix">Sample STIX 2.1</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-sample="misp">Sample MISP event</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-sample="list">Sample IOC list</button>
      </div>
      <div id="ti-msg" class="lx-mt" role="status"></div>
    </div>
    <div class="lx-pane">
      <h2 class="lx-h2">Normalization contract</h2>
      <div class="lx-formula">indicator_value   canonical, refanged, lower-cased where case-insensitive
indicator_type    ipv4 · ipv6 · domain · url · email · md5 · sha1 · sha256 · sha512 · filename · asn · registry
confidence        0–100 (STIX confidence · OpenCTI score · MISP to_ids / confidence tags)
mitre_attack_id   T####[.###] from "indicates" relationships, galaxy tags or text
dedup_key         SHA-256(indicator_type + "|" + indicator_value)</div>
      <p class="lx-help">Duplicates across feeds merge into one record: the highest confidence wins and sources, techniques and
        first-seen dates are combined. Enrichment is <b>simulated</b> (tagged SIM) except the IP-range classes, which are real.
        No external lookups are made.</p>
    </div>
  </div>
  <div class="lx-pane lx-mt">
    <div class="lx-row lx-row--between">
      <div class="lx-stats" id="ti-stats"></div>
      <div class="lx-row">
        <label class="lx-sr" for="ti-filter">Filter</label>
        <input id="ti-filter" class="lx-input" style="width:220px" placeholder="Filter value, type, T-id…" maxlength="120">
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="ti-json">Export JSON</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="ti-csv">Export CSV</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="ti-clear">Clear</button>
      </div>
    </div>
    <div class="lx-tablewrap"><table class="lx-table">
      <thead><tr><th>indicator_value</th><th>type</th><th>confidence</th><th>mitre_attack_id</th><th>sources</th><th>enrichment</th><th>dedup_key</th></tr></thead>
      <tbody id="ti-rows"><tr><td colspan="7" class="lx-muted">No indicators yet. Load a sample or paste a feed.</td></tr></tbody>
    </table></div>
  </div>
</section>

<!-- ═══ B · UEBA ════════════════════════════════════════════════════════ -->
<section role="tabpanel" id="sl-p-ueba" aria-labelledby="sl-t-ueba" hidden>
  <div class="lx-grid-2">
    <div class="lx-pane">
      <div class="lx-row lx-row--between"><h2 class="lx-h2">Behavioural signals</h2>
        <div class="lx-row" id="ub-presets" aria-label="Scenario presets"></div></div>
      <div class="lx-stack lx-mt" id="ub-sliders"></div>
      <hr class="lx-sep">
      <h2 class="lx-h3">Weights <span class="lx-muted" style="text-transform:none;letter-spacing:0">(renormalized to sum to 1)</span></h2>
      <div class="lx-grid-4" id="ub-weights"></div>
    </div>
    <div class="lx-pane">
      <div class="lx-gauge">
        <svg width="132" height="132" viewBox="0 0 132 132" aria-hidden="true">
          <circle cx="66" cy="66" r="56" fill="none" stroke="var(--lx-hair-strong)" stroke-width="10"/>
          <circle id="ub-arc" cx="66" cy="66" r="56" fill="none" stroke="var(--lx-ok)" stroke-width="10" stroke-linecap="round"
                  stroke-dasharray="351.86" stroke-dashoffset="351.86" transform="rotate(-90 66 66)" style="transition:stroke-dashoffset .35s, stroke .35s"/>
        </svg>
        <div>
          <div class="lx-gauge-num" id="ub-score" aria-live="polite">0</div>
          <span class="lx-badge lx-badge--ok" id="ub-tier">Normal</span>
          <p class="lx-help" id="ub-action"></p>
        </div>
      </div>
      <hr class="lx-sep">
      <h2 class="lx-h3">Contribution</h2>
      <div class="lx-bars" id="ub-bars"></div>
      <h2 class="lx-h3 lx-mt">Correlation rules <span class="lx-muted" style="text-transform:none;letter-spacing:0">(bonus capped at +25)</span></h2>
      <ul class="lx-rules" id="ub-rules"></ul>
      <h2 class="lx-h3 lx-mt">Formula</h2>
      <div class="lx-formula" id="ub-formula"></div>
    </div>
  </div>
</section>

<!-- ═══ C · Scaling ═════════════════════════════════════════════════════ -->
<section role="tabpanel" id="sl-p-eps" aria-labelledby="sl-t-eps" hidden>
  <div class="lx-grid-2">
    <div class="lx-pane">
      <div class="lx-row lx-row--between"><h2 class="lx-h2">Event velocity</h2>
        <div class="lx-row"><button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-eps="15000000:eps">15M EPS</button>
          <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-eps="15000000:day">15M / day</button>
          <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" data-eps="50000:eps">50k EPS</button></div></div>
      <form id="eps-form" class="lx-grid-2 lx-mt" onsubmit="return false">
        <div><label class="lx-label" for="eps-rate">Event rate</label><input class="lx-input" id="eps-rate" type="number" min="1" step="any" value="15000000"></div>
        <div><label class="lx-label" for="eps-unit">Unit</label><select class="lx-select" id="eps-unit"><option value="eps" selected>events / second</option><option value="day">events / day</option></select></div>
        <div><label class="lx-label" for="eps-size">Avg event size (bytes)</label><input class="lx-input" id="eps-size" type="number" min="50" step="10" value="700"></div>
        <div><label class="lx-label" for="eps-filter">Dropped at source (%)</label><input class="lx-input" id="eps-filter" type="number" min="0" max="99" value="40"></div>
        <div><label class="lx-label" for="eps-hotdays">Hot retention (days)</label><input class="lx-input" id="eps-hotdays" type="number" min="0" value="7"></div>
        <div><label class="lx-label" for="eps-warmdays">Warm retention (days)</label><input class="lx-input" id="eps-warmdays" type="number" min="0" value="30"></div>
        <div><label class="lx-label" for="eps-colddays">Cold retention (days)</label><input class="lx-input" id="eps-colddays" type="number" min="0" value="365"></div>
        <div><label class="lx-label" for="eps-replicas">Hot/warm replicas</label><input class="lx-input" id="eps-replicas" type="number" min="0" max="3" value="1"></div>
        <div><label class="lx-label" for="eps-ratio">Index on-disk ratio (hot/warm)</label><input class="lx-input" id="eps-ratio" type="number" min="0.05" step="0.05" value="0.6"></div>
        <div><label class="lx-label" for="eps-coldratio">Archive ratio (cold, zstd)</label><input class="lx-input" id="eps-coldratio" type="number" min="0.01" step="0.01" value="0.12"></div>
        <div><label class="lx-label" for="eps-p-hot">Hot NVMe $/GB-month</label><input class="lx-input" id="eps-p-hot" type="number" min="0" step="0.001" value="0.20"></div>
        <div><label class="lx-label" for="eps-p-warm">Warm SSD $/GB-month</label><input class="lx-input" id="eps-p-warm" type="number" min="0" step="0.001" value="0.08"></div>
        <div><label class="lx-label" for="eps-p-cold">Cold S3/Glacier $/GB-month</label><input class="lx-input" id="eps-p-cold" type="number" min="0" step="0.0001" value="0.004"></div>
        <div><label class="lx-label" for="eps-node">Usable TB per hot node</label><input class="lx-input" id="eps-node" type="number" min="0.5" step="0.5" value="6"></div>
      </form>
      <p class="lx-help">Prices are editable example figures, not quotes. Sizes use decimal units (1 GB = 10⁹ bytes).</p>
    </div>
    <div class="lx-pane">
      <h2 class="lx-h2">Sizing</h2>
      <div class="lx-stats" id="eps-stats"></div>
      <div class="lx-tablewrap"><table class="lx-table"><thead><tr><th>Tier</th><th>Retention</th><th>Stored</th><th>Monthly cost</th></tr></thead><tbody id="eps-tiers"></tbody></table></div>
      <h2 class="lx-h3 lx-mt">Time-partitioned indexing</h2>
      <dl class="lx-kv" id="eps-index"></dl>
      <h2 class="lx-h3 lx-mt">Ingestion bus</h2>
      <dl class="lx-kv" id="eps-kafka"></dl>
      <div id="eps-advice" class="lx-mt"></div>
    </div>
  </div>
</section>

<!-- ═══ D · Pipeline mapper ═════════════════════════════════════════════ -->
<section role="tabpanel" id="sl-p-pipe" aria-labelledby="sl-t-pipe" hidden>
  <div class="lx-grid-3">
    <div class="lx-pane"><label class="lx-label" for="pl-m365">Microsoft 365 Purview · unified audit log</label>
      <textarea id="pl-m365" class="lx-textarea" rows="8" spellcheck="false"></textarea></div>
    <div class="lx-pane"><label class="lx-label" for="pl-aws">AWS CloudTrail · Records[]</label>
      <textarea id="pl-aws" class="lx-textarea" rows="8" spellcheck="false"></textarea></div>
    <div class="lx-pane"><label class="lx-label" for="pl-k8s">Kubernetes audit · via Fluent Bit</label>
      <textarea id="pl-k8s" class="lx-textarea" rows="8" spellcheck="false"></textarea></div>
  </div>
  <div class="lx-grid-2 lx-mt">
    <div class="lx-pane">
      <label class="lx-label" for="pl-ids">Identity map <span class="lx-muted">(any alias → one canonical person, JSON)</span></label>
      <textarea id="pl-ids" class="lx-textarea" rows="5" spellcheck="false"></textarea>
    </div>
    <div class="lx-pane">
      <div class="lx-grid-2">
        <div><label class="lx-label" for="pl-window">Correlation window (minutes)</label><input id="pl-window" class="lx-input" type="number" min="1" max="1440" value="30"></div>
        <div><label class="lx-label" for="pl-by">Correlate by</label><select id="pl-by" class="lx-select"><option value="both">identity and source IP</option><option value="actor">identity only</option><option value="ip">source IP only</option></select></div>
      </div>
      <div class="lx-row lx-mt"><button type="button" class="lx-btn btn-beam" id="pl-run">Build unified timeline</button>
        <button type="button" class="lx-btn lx-btn--ghost lx-btn--sm" id="pl-sample">Load incident sample</button></div>
      <div id="pl-msg" class="lx-mt" role="status"></div>
    </div>
  </div>
  <div class="lx-pane lx-mt">
    <div class="lx-row lx-row--between"><h2 class="lx-h2">Unified audit timeline</h2>
      <div class="lx-legend"><span><i style="background:var(--lx-accent)"></i>same identity</span><span><i style="background:var(--lx-info)"></i>same source IP</span><span><i style="background:var(--lx-warn);height:9px;width:9px;border-radius:50%"></i>suspicious action</span></div></div>
    <div class="lx-row lx-mt" id="pl-focus" aria-label="Timeline focus"></div>
    <div id="pl-chart" class="lx-mt"></div>
    <div id="pl-detail" class="lx-mt" role="status"></div>
    <div class="lx-tablewrap lx-mt"><table class="lx-table lx-table--nowrap"><thead><tr><th>@timestamp</th><th>source</th><th>identity</th><th>action</th><th>target</th><th>source.ip</th><th>chain</th></tr></thead><tbody id="pl-rows"></tbody></table></div>
  </div>
</section>
<?php labs_foot(['labs-common', 'siem-lab']); ?>

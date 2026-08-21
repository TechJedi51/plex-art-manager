'use strict';

/* ==========================================================================
   Tiny helpers
   ========================================================================== */

async function api(path, options = {}) {
    const res = await fetch('api/' + path, {
        headers: options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' },
        ...options,
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON error page */ }
    if (!res.ok) {
        const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
        throw new Error(msg);
    }
    return data;
}

function el(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function toast(message, type = 'info') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = el('<div id="toast-container" class="toast-container"></div>');
        document.body.appendChild(container);
    }
    const node = el(`<div class="toast ${type === 'error' ? 'error' : type === 'success' ? 'success' : ''}">${esc(message)}</div>`);
    container.appendChild(node);
    setTimeout(() => node.remove(), 5000);
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

const ASSET_TYPES = ['poster', 'art', 'square', 'logo'];
const ASSET_LABELS = { poster: 'Poster', art: 'Background', square: 'Square Art', logo: 'Logo' };
// Referenced from the project's images/ folder - see README.md for the exact
// files expected there (background-icon.svg, logo-icon.png, poster-icon.png,
// square-art-icon.svg). Sized to match the status emoji next to them.
const ASSET_ICONS = {
    poster: 'images/poster-icon.png',
    art: 'images/background-icon.svg',
    square: 'images/square-art-icon.svg',
    logo: 'images/logo-icon.png',
};

const STATUS_LABELS = {
    new: 'New', updated: 'Updated', unchanged: 'Unchanged',
    kept_existing: 'Kept Existing', failed: 'Failed',
    would_create: 'Would Create', would_update_or_match: 'Would Update/Match', would_fail: 'Would Fail',
};

const BADGE_CLASS = {
    new: 'new', updated: 'updated', unchanged: 'unchanged',
    kept_existing: 'kept_existing', failed: 'failed',
    would_create: 'new', would_update_or_match: 'unchanged', would_fail: 'failed',
};

function statusBadge(status) {
    if (!status) return '<span class="badge badge-none">—</span>';
    const cls = BADGE_CLASS[status] || 'none';
    return `<span class="badge badge-${cls}">${esc(STATUS_LABELS[status] || status)}</span>`;
}

/* Emoji symbols used on the Movies page's per-asset-type columns. */
const STATUS_EMOJI = {
    new: '🆕', updated: '🔄', unchanged: '🟡',
    kept_existing: '🟠', failed: '⛔️', ignored: '🔕', none: '➖',
};
const STATUS_EMOJI_LABELS = {
    new: 'New', updated: 'Updated', unchanged: 'Unchanged',
    kept_existing: 'Kept Existing', failed: 'Failed', ignored: 'Ignored', none: 'Not Checked',
};
const EFFECTIVE_STATUS_ORDER = ['new', 'updated', 'unchanged', 'kept_existing', 'failed', 'ignored', 'none'];

function statusEmoji(status) {
    const key = status || 'none';
    const label = STATUS_EMOJI_LABELS[key] || key;
    return `<span class="status-emoji" title="${esc(label)}">${STATUS_EMOJI[key] || '➖'}</span>`;
}

function renderLegend() {
    const items = EFFECTIVE_STATUS_ORDER.map(k => `<div class="legend-item">${statusEmoji(k)} <span>${esc(STATUS_EMOJI_LABELS[k])}</span></div>`).join('');
    return `<div class="legend"><div class="legend-title">Legend</div><div class="legend-items">${items}</div></div>`;
}

function sortArrow(activeKey, thisKey, dir) {
    if (activeKey !== thisKey) return '';
    return `<span class="sort-arrow">${dir === 'asc' ? '▲' : '▼'}</span>`;
}

function assetFilterOptions(current) {
    return EFFECTIVE_STATUS_ORDER.map(k => `<option value="${k}" ${current === k ? 'selected' : ''}>${esc(STATUS_EMOJI_LABELS[k])}</option>`).join('');
}

/* ==========================================================================
   Router
   ========================================================================== */

const routes = {
    '#/dashboard': viewDashboard,
    '#/batch': viewBatch,
    '#/movies': viewMovies,
    '#/review': viewReview,
    '#/diagnostics': viewDiagnostics,
    '#/logs': viewLogs,
    '#/settings': viewSettings,
    '#/help': viewHelp,
};

// Batch/Movies/Dashboard all poll job status while a background job is
// active (see startJobPolling() below) - one place to stop that poller
// whenever the route changes, instead of every view remembering to clean up
// its own interval.
let activePoll = null;
function stopPolling() {
    if (activePoll) {
        clearInterval(activePoll);
        activePoll = null;
    }
}

/**
 * Polls api/jobs_status.php?id=<jobId> every ~1.5s and calls onUpdate(job)
 * with each result, stopping itself once the job reaches a terminal status.
 * Shared by the Dashboard, Batch Process, and Movies (Sync Library) screens
 * so all three reattach to an in-progress job the same way.
 */
function startJobPolling(jobId, onUpdate) {
    stopPolling();
    const poll = async () => {
        let job;
        try {
            job = (await api('jobs_status.php?id=' + jobId)).job;
        } catch (e) {
            stopPolling();
            toast('Lost track of job status: ' + e.message, 'error');
            return;
        }
        onUpdate(job);
        if (!job || ['done', 'failed', 'cancelled'].includes(job.status)) {
            stopPolling();
        }
    };
    poll();
    activePoll = setInterval(poll, 1500);
}

function jobStatusBadge(status) {
    const cls = { queued: 'badge-queued', running: 'badge-running', cancelled: 'badge-cancelled', failed: 'badge-failed', done: 'badge-new' }[status] || 'badge-none';
    return `<span class="badge ${cls}">${esc(status)}</span>`;
}

function jobProgressLabel(job) {
    if (job.status === 'queued') return 'Queued — waiting for the job worker…';
    if (job.status === 'failed') return `Failed: ${job.error || 'unknown error'}`;
    if (job.status === 'cancelled') return `Stopped at ${job.cursor}${job.totalSize != null ? ' of ' + job.totalSize : ''}.`;
    if (job.status === 'done') return `Complete — ${job.cursor}${job.totalSize != null ? ' of ' + job.totalSize : ''} processed.`;
    return job.totalSize != null ? `${job.cursor} of ${job.totalSize} (${job.pct}%)` : `${job.cursor} processed…`;
}

function currentRoute() {
    const hash = location.hash || '#/dashboard';
    if (hash.startsWith('#/movies/')) return { view: 'movieDetail', ratingKey: hash.split('/')[2], query: new URLSearchParams() };
    const qIndex = hash.indexOf('?');
    const view = qIndex === -1 ? hash : hash.slice(0, qIndex);
    const query = new URLSearchParams(qIndex === -1 ? '' : hash.slice(qIndex + 1));
    return { view, ratingKey: null, query };
}

async function router() {
    stopPolling();
    const route = currentRoute();
    highlightNav(route.view === 'movieDetail' ? '#/movies' : route.view);
    const content = document.getElementById('content');
    const toolbar = document.getElementById('toolbar');
    toolbar.innerHTML = '';
    content.innerHTML = '<div class="empty-state"><span class="spinner"></span> Loading…</div>';

    try {
        if (route.view === 'movieDetail') {
            await viewMovieDetail(content, toolbar, route.ratingKey);
        } else if (routes[route.view]) {
            await routes[route.view](content, toolbar, route.query);
        } else {
            location.hash = '#/dashboard';
        }
    } catch (e) {
        content.innerHTML = `<div class="banner banner-danger">${esc(e.message)}</div>`;
    }
}

function highlightNav(hash) {
    document.querySelectorAll('#sidebar .nav-item').forEach(n => {
        n.classList.toggle('active', n.dataset.route === hash);
    });
}

window.addEventListener('hashchange', router);
window.addEventListener('DOMContentLoaded', router);

// router()'s own try/catch only covers errors thrown while rendering a view.
// Anything else uncaught (a bug outside that path, a failed background
// promise) would otherwise fail silently and leave whatever was on screen -
// often just the loading spinner - stuck there with zero indication why.
function showFatalError(message) {
    console.error('Unhandled error:', message);
    const content = document.getElementById('content');
    if (content) {
        content.innerHTML = `<div class="banner banner-danger">Unexpected error: ${esc(String(message))}. Check the browser console for details.</div>`;
    }
}
window.addEventListener('error', (e) => showFatalError(e.message));
window.addEventListener('unhandledrejection', (e) => showFatalError(e.reason && e.reason.message ? e.reason.message : e.reason));

/* ==========================================================================
   Dashboard
   ========================================================================== */

async function viewDashboard(content, toolbar) {
    toolbar.innerHTML = `<span class="toolbar-title"><span class="icon-mask icon-mask-dashboard"></span>Dashboard</span>`;

    const stats = await api('stats.php');

    if (!stats.plexConfigured) {
        content.innerHTML = `
            <div class="banner banner-warn">
                Plex isn't configured yet. Head to <a href="#/settings">Settings</a> and add your Plex URL and token to get started.
            </div>`;
        return;
    }

    const activeJob = (await api('jobs_status.php')).job;

    const pendingRows = ASSET_TYPES.map(t => `
        <a class="stat-tile" href="#/review?types=${t}">
            <div class="num">${stats.pendingByType[t] || 0}</div>
            <div class="label">${ASSET_LABELS[t]} Missing</div>
        </a>`).join('');

    const recentRows = stats.recentChanges.length
        ? stats.recentChanges.map(r => `
            <div class="log-line">
                <span class="title">${esc(r.title)}</span>
                — ${ASSET_LABELS[r.asset_type] || r.asset_type} ${statusBadge(r.status)}
                <span style="color:var(--text-muted)"> via ${esc(r.source)} · ${fmtDate(r.changed_at)}</span>
            </div>`).join('')
        : '<div class="empty-state">No changes yet — run a batch to get started.</div>';

    content.innerHTML = `
        <div id="dash-job-status"></div>
        <div class="section-block">
            <div class="stat-grid">
                <a class="stat-tile" href="#/movies"><div class="num">${stats.totalMovies}</div><div class="label">Movies Tracked</div></a>
                <a class="stat-tile" href="#/review"><div class="num">${stats.totalPending}</div><div class="label">Need Review</div></a>
                ${pendingRows}
            </div>
        </div>
        <div class="section-block">
            <h2 class="section-title">Recent Changes</h2>
            <div class="card">${recentRows}</div>
        </div>
        <div class="pill-row">
            <a class="btn btn-primary" href="#/batch">Run a Batch</a>
            <a class="btn" href="#/movies">Browse Movies</a>
            <a class="btn" href="#/review">Needs Review (${stats.totalPending})</a>
        </div>
    `;

    // Read-only: shows a job's live progress if one happens to be running,
    // with a link through to the screen that has Start/Stop controls -
    // Dashboard itself doesn't start or cancel jobs.
    if (activeJob) {
        renderDashboardJobStatus(document.getElementById('dash-job-status'), activeJob);
        startJobPolling(activeJob.id, job => renderDashboardJobStatus(document.getElementById('dash-job-status'), job));
    }
}

function renderDashboardJobStatus(box, job) {
    if (!box) return;
    const label = job.type === 'batch' ? 'Batch Process' : 'Sync Library';
    const linkHref = job.type === 'batch' ? '#/batch' : '#/movies';
    box.innerHTML = `
        <div class="card section-block">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div>${label} running — <strong>${esc(job.sectionTitle || '')}</strong> ${jobStatusBadge(job.status)}</div>
                <a class="btn btn-primary" href="${linkHref}">View Progress →</a>
            </div>
            <div class="progress-outer"><div class="progress-inner" style="width:${job.pct}%"></div></div>
            <div style="font-size:12px;color:var(--text-muted)">${jobProgressLabel(job)}</div>
        </div>
    `;
}

/* ==========================================================================
   Batch Process
   ========================================================================== */

async function viewBatch(content, toolbar) {
    toolbar.innerHTML = `<span class="toolbar-title"><span class="icon-mask icon-mask-batch"></span>Batch Process</span>`;

    let libraries = [];
    try {
        libraries = (await api('libraries.php')).libraries.filter(l => l.type === 'movie');
    } catch (e) {
        content.innerHTML = `<div class="banner banner-danger">${esc(e.message)}</div>`;
        return;
    }
    if (!libraries.length) {
        content.innerHTML = `<div class="banner banner-warn">No movie libraries found on Plex. Check your Settings.</div>`;
        return;
    }

    const defaultSize = (await api('settings.php')).batch_default_size || '25';
    const activeJob = (await api('jobs_status.php')).job;

    content.innerHTML = `
        <div class="banner banner-warn" id="b-blocked-banner" style="display:none"></div>
        <div class="card section-block" id="b-form-card">
            <div class="setting-row">
                <div class="label">Library</div>
                <div class="control-wrap">
                    <select id="b-library">${libraries.map(l => `<option value="${l.id}">${esc(l.title)}</option>`).join('')}</select>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">Asset Types</div>
                <div class="control-wrap">
                    ${ASSET_TYPES.map(t => `
                        <label class="checkbox" style="margin-right:16px;">
                            <input type="checkbox" class="b-asset" value="${t}" ${t === 'poster' || t === 'art' ? 'checked' : ''}> ${ASSET_LABELS[t]}
                        </label>`).join('')}
                    <div class="help">Square Art and Logo require an extra Plex API call per movie, so they run slower than Poster/Background.</div>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">Movies to Process</div>
                <div class="control-wrap">
                    <label class="checkbox"><input type="radio" name="b-mode" id="b-mode-custom" checked> Custom amount</label>
                    <label class="checkbox" style="margin-left:20px;"><input type="radio" name="b-mode" id="b-mode-all"> All movies in this library (<span id="b-all-count">…</span>)</label>
                    <div id="b-custom-controls" style="margin-top:10px;">
                        <input type="number" id="b-limit" value="${esc(defaultSize)}" min="1" style="width:120px">
                        <div class="help">Total number of movies this run should process, starting from Start. Runs in small chunks in the background regardless of this number, so progress updates stay frequent even on a large run.</div>
                    </div>
                </div>
            </div>
            <div class="setting-row" id="b-startstop-row">
                <div class="label">Start / Stop</div>
                <div class="control-wrap" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="number" id="b-start" value="0" min="0" style="width:110px" placeholder="Start">
                    <span>to</span>
                    <input type="number" id="b-stop" placeholder="Start + Limit" style="width:150px">
                    <div class="help" style="flex-basis:100%">Leave Stop blank to process exactly the number of movies set above, starting at Start. Set an explicit Stop to run a bigger range in one sitting — e.g. Start 0, Stop 500 works through the first 500 movies.</div>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">Dry Run</div>
                <div class="control-wrap">
                    <label class="checkbox"><input type="checkbox" id="b-dryrun"> Preview what would change — no files are written</label>
                </div>
            </div>
        </div>
        <div class="pill-row">
            <button class="btn btn-primary" id="b-start-btn">Run Batch</button>
            <button class="btn btn-danger" id="b-stop-btn" style="display:none">Stop</button>
        </div>
        <div id="b-progress" style="display:none">
            <div style="font-size:13px; margin-bottom:6px;" id="b-status-line"></div>
            <div class="progress-outer"><div class="progress-inner" id="b-progress-bar" style="width:0%"></div></div>
            <div id="b-progress-label" style="font-size:12px;color:var(--text-muted)"></div>
        </div>
        <div id="batch-log"></div>
        <div id="batch-summary"></div>
    `;

    const librarySelect = document.getElementById('b-library');
    const allCountEl = document.getElementById('b-all-count');
    const modeCustom = document.getElementById('b-mode-custom');
    const modeAll = document.getElementById('b-mode-all');
    const customControls = document.getElementById('b-custom-controls');
    const startStopRow = document.getElementById('b-startstop-row');

    async function refreshCount() {
        allCountEl.textContent = '…';
        try {
            const { total } = await api('library_count.php?sectionId=' + librarySelect.value);
            allCountEl.textContent = total;
        } catch (e) {
            allCountEl.textContent = '?';
        }
    }
    function applyMode() {
        const allMode = modeAll.checked;
        customControls.style.display = allMode ? 'none' : '';
        startStopRow.style.display = allMode ? 'none' : '';
    }
    librarySelect.addEventListener('change', refreshCount);
    modeCustom.addEventListener('change', applyMode);
    modeAll.addEventListener('change', applyMode);
    refreshCount();
    applyMode();

    function enterBatchProgressMode(job) {
        document.getElementById('b-form-card').style.display = 'none';
        document.getElementById('b-start-btn').style.display = 'none';
        document.getElementById('b-stop-btn').style.display = '';
        document.getElementById('b-stop-btn').dataset.jobId = job.id;
        document.getElementById('b-progress').style.display = '';
        document.getElementById('batch-log').innerHTML = '';
        document.getElementById('batch-summary').innerHTML = '';
        renderBatchProgress(job);
        startJobPolling(job.id, updated => {
            renderBatchProgress(updated);
            if (['done', 'failed', 'cancelled'].includes(updated.status)) {
                document.getElementById('b-form-card').style.display = '';
                document.getElementById('b-start-btn').style.display = '';
                document.getElementById('b-stop-btn').style.display = 'none';
                document.getElementById('b-progress').style.display = 'none';
                renderBatchSummary(document.getElementById('batch-summary'), updated);
                if (updated.status === 'cancelled') toast('Batch stopped.', 'info');
                else if (updated.status === 'failed') toast('Batch failed: ' + (updated.error || 'unknown error'), 'error');
                else toast('Batch complete.', 'success');
            }
        });
    }

    function renderBatchProgress(job) {
        document.getElementById('b-status-line').innerHTML = jobStatusBadge(job.status);
        document.getElementById('b-progress-bar').style.width = job.pct + '%';
        document.getElementById('b-progress-label').textContent = jobProgressLabel(job);
        const log = document.getElementById('batch-log');
        log.innerHTML = job.recentItems.map(i => `<div class="log-line"><span class="title">${esc(i.title)}</span> — ${ASSET_LABELS[i.assetType] || i.assetType} ${statusBadge(i.status)}${i.error ? ': ' + esc(i.error) : ''}</div>`).join('');
    }

    if (activeJob && activeJob.type === 'batch') {
        enterBatchProgressMode(activeJob);
    } else if (activeJob && activeJob.type === 'sync') {
        document.getElementById('b-start-btn').disabled = true;
        const banner = document.getElementById('b-blocked-banner');
        banner.style.display = '';
        banner.textContent = 'A library sync is currently running — see the Movies screen. Batch can start once it finishes.';
    }

    document.getElementById('b-start-btn').addEventListener('click', async () => {
        const sectionId = parseInt(librarySelect.value, 10);
        const sectionTitle = librarySelect.selectedOptions[0]?.textContent;
        const assetTypes = [...document.querySelectorAll('.b-asset:checked')].map(c => c.value);
        if (!assetTypes.length) { toast('Choose at least one asset type', 'error'); return; }
        const dryRun = document.getElementById('b-dryrun').checked;

        const body = { type: 'batch', sectionId, sectionTitle, assetTypes, dryRun };
        if (modeAll.checked) {
            body.allMovies = true;
        } else {
            const totalToProcess = Math.max(1, parseInt(document.getElementById('b-limit').value, 10) || 25);
            const start = Math.max(0, parseInt(document.getElementById('b-start').value, 10) || 0);
            const stopInput = document.getElementById('b-stop').value;
            // Blank Stop means "just this many movies" (Start + Movies to Process),
            // NOT "run to the end of the library".
            body.start = start;
            body.stop = stopInput ? parseInt(stopInput, 10) : (start + totalToProcess);
        }

        try {
            const { job } = await api('jobs_start.php', { method: 'POST', body: JSON.stringify(body) });
            enterBatchProgressMode(job);
        } catch (e) {
            toast(e.message, 'error');
        }
    });

    document.getElementById('b-stop-btn').addEventListener('click', async e => {
        try {
            await api('jobs_cancel.php', { method: 'POST', body: JSON.stringify({ id: parseInt(e.target.dataset.jobId, 10) }) });
        } catch (err) {
            toast(err.message, 'error');
        }
    });
}

/**
 * job.counts/job.recentItems come from the server now instead of being
 * accumulated client-side chunk by chunk - recentItems is a capped ring
 * buffer (most recent 50), so on a very large run this table shows the most
 * recent changes/failures, not necessarily every single one.
 */
function renderBatchSummary(box, job) {
    const totals = Object.assign({ new: 0, updated: 0, unchanged: 0, failed: 0, kept_existing: 0 }, job.counts);
    const changedItems = job.recentItems.filter(i => ['new', 'updated', 'would_create', 'would_update_or_match'].includes(i.status));
    const failedItems = job.recentItems.filter(i => ['failed', 'would_fail'].includes(i.status));
    const changedCount = changedItems.length;
    const failedCount = failedItems.length;

    let html = `
        <h2 class="section-title">Summary${job.status === 'cancelled' ? ' (stopped early)' : ''}</h2>
        <div class="stat-grid">
            <div class="stat-tile"><div class="num">${totals.new}</div><div class="label">New</div></div>
            <div class="stat-tile"><div class="num">${totals.updated}</div><div class="label">Updated</div></div>
            <div class="stat-tile"><div class="num">${totals.unchanged}</div><div class="label">Unchanged</div></div>
            <div class="stat-tile"><div class="num">${totals.kept_existing}</div><div class="label">Kept Existing</div></div>
            <div class="stat-tile"><div class="num">${totals.failed}</div><div class="label">Failed</div></div>
        </div>
    `;

    if (changedCount) {
        html += `<h3>Changed Items (most recent ${changedCount})</h3><table class="data-table"><thead><tr><th>Title</th><th>Path</th><th>Changed</th></tr></thead><tbody>`;
        for (const c of changedItems) {
            html += `<tr><td>${esc(c.title)}</td><td class="path">${esc(c.path || '')}</td><td>${ASSET_LABELS[c.assetType] || c.assetType} ${statusBadge(c.status)}</td></tr>`;
        }
        html += `</tbody></table>`;
    }

    if (failedCount) {
        html += `<h3 style="margin-top:20px">Failed Items (most recent ${failedCount})</h3><table class="data-table"><thead><tr><th>Title</th><th>Path</th><th>Asset</th><th>Error</th></tr></thead><tbody>`;
        for (const f of failedItems) {
            html += `<tr><td>${esc(f.title)}</td><td class="path">${esc(f.path || '')}</td><td>${ASSET_LABELS[f.assetType] || f.assetType}</td><td>${esc(f.error || '')}</td></tr>`;
        }
        html += `</tbody></table>`;
    }

    if (!changedCount && !failedCount) {
        html += `<div class="empty-state">All images were already up to date. No changes made.</div>`;
    }

    box.innerHTML = html;
}

/* ==========================================================================
   Movies list
   ========================================================================== */

async function viewMovies(content, toolbar) {
    const state = {
        q: '', sort: 'title', dir: 'asc', offset: 0, limit: 50,
        filters: { poster: '', art: '', square: '', logo: '' },
    };

    toolbar.innerHTML = `
        <span class="toolbar-title"><span class="icon-mask icon-mask-movies"></span>Movies</span>
        <input type="search" id="m-search" placeholder="Search movies…" style="width:220px">
        <select id="m-sort">
            <option value="title">Sort: Title</option>
            <option value="year">Sort: Year</option>
            <option value="rating_key">Sort: Plex ID</option>
        </select>
        <button class="btn" id="m-sort-dir" title="Toggle ascending/descending">↑ Asc</button>
        ${ASSET_TYPES.map(t => `
            <label style="color:#c2c7cc;font-size:12px;display:flex;align-items:center;gap:4px;">
                ${ASSET_LABELS[t]}
                <select class="m-filter" data-type="${t}">
                    <option value="">Any</option>
                    ${assetFilterOptions('')}
                </select>
            </label>`).join('')}
    `;

    content.innerHTML = `
        <div class="card section-block" id="sync-card">
            <h3 style="margin-top:0">Sync Library</h3>
            <p style="color:var(--text-muted); font-size:13px; margin-top:0;">
                Pulls title, path, and TMDB id for every movie in the library from Plex - fast and
                metadata-only, no artwork is downloaded. Run this before your first Batch, or any time
                titles/paths may have changed in Plex.
            </p>
            <div id="sync-blocked-banner" class="banner banner-warn" style="display:none"></div>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span id="m-library-slot"></span>
                <button class="btn btn-primary" id="m-sync">Sync Library</button>
                <button class="btn btn-danger" id="m-sync-stop" style="display:none">Stop</button>
                <span id="sync-summary" style="font-size:12px;color:var(--text-muted)"></span>
            </div>
            <div id="sync-progress" style="display:none; margin-top:14px;">
                <div class="progress-outer"><div class="progress-inner" id="sync-progress-bar" style="width:0%"></div></div>
                <div id="sync-current" style="font-size:12px;color:var(--text-muted); margin-top:6px;"></div>
                <div id="sync-log"></div>
            </div>
        </div>
        <div id="movies-table-area"></div>
    `;
    const tableArea = document.getElementById('movies-table-area');

    let libraries = [];
    try { libraries = (await api('libraries.php')).libraries.filter(l => l.type === 'movie'); } catch (e) { /* ignore for list view */ }
    if (libraries.length) {
        document.getElementById('m-library-slot').innerHTML =
            `<select id="m-library">${libraries.map(l => `<option value="${l.id}">${esc(l.title)}</option>`).join('')}</select>`;
    }

    async function startSync() {
        const sectionId = parseInt(document.getElementById('m-library')?.value, 10);
        if (!sectionId) { toast('No movie library available to sync', 'error'); return; }
        const sectionTitle = document.getElementById('m-library').selectedOptions[0]?.textContent;
        try {
            const { job } = await api('jobs_start.php', { method: 'POST', body: JSON.stringify({ type: 'sync', sectionId, sectionTitle }) });
            enterSyncProgressMode(job);
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    function enterSyncProgressMode(job) {
        document.getElementById('m-sync').style.display = 'none';
        const stopBtn = document.getElementById('m-sync-stop');
        stopBtn.style.display = '';
        stopBtn.dataset.jobId = job.id;
        document.getElementById('sync-progress').style.display = '';
        renderSyncProgress(job);
        startJobPolling(job.id, updated => {
            renderSyncProgress(updated);
            if (['done', 'failed', 'cancelled'].includes(updated.status)) {
                document.getElementById('m-sync').style.display = '';
                stopBtn.style.display = 'none';
                if (updated.status === 'cancelled') toast('Sync stopped.', 'info');
                else if (updated.status === 'failed') toast('Sync failed: ' + (updated.error || 'unknown error'), 'error');
                else toast('Library sync complete.', 'success');
                load();
            }
        });
    }

    function renderSyncProgress(job) {
        document.getElementById('sync-current').innerHTML = jobStatusBadge(job.status);
        document.getElementById('sync-progress-bar').style.width = job.pct + '%';
        document.getElementById('sync-summary').textContent = jobProgressLabel(job);
        document.getElementById('sync-log').innerHTML = job.recentItems.map(i =>
            `<div class="log-line">⚠️ Skipped <span class="title">${esc(i.title)}</span> — ${esc(i.error || '')}</div>`
        ).join('');
    }

    const activeJob = (await api('jobs_status.php')).job;
    if (activeJob && activeJob.type === 'sync') {
        enterSyncProgressMode(activeJob);
    } else if (activeJob && activeJob.type === 'batch') {
        document.getElementById('m-sync').disabled = true;
        const banner = document.getElementById('sync-blocked-banner');
        banner.style.display = '';
        banner.textContent = 'A batch process is currently running — see the Batch Process screen. Sync can start once it finishes.';
    }

    async function load() {
        tableArea.innerHTML = '<div class="empty-state"><span class="spinner"></span> Loading…</div>';
        const params = new URLSearchParams({ q: state.q, limit: state.limit, offset: state.offset, sort: state.sort, dir: state.dir });
        for (const [type, val] of Object.entries(state.filters)) {
            if (val) params.set(type, val);
        }
        const data = await api('movies.php?' + params.toString());
        renderMovieTable(tableArea, data);
    }

    document.getElementById('m-search').addEventListener('input', debounce(e => { state.q = e.target.value; state.offset = 0; load(); }, 300));
    document.getElementById('m-sync').addEventListener('click', startSync);
    document.getElementById('m-sync-stop').addEventListener('click', async e => {
        try {
            await api('jobs_cancel.php', { method: 'POST', body: JSON.stringify({ id: parseInt(e.target.dataset.jobId, 10) }) });
        } catch (err) {
            toast(err.message, 'error');
        }
    });
    document.getElementById('m-sort').addEventListener('change', e => { state.sort = e.target.value; state.offset = 0; load(); });
    document.getElementById('m-sort-dir').addEventListener('click', () => {
        state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        document.getElementById('m-sort-dir').textContent = state.dir === 'asc' ? '↑ Asc' : '↓ Desc';
        load();
    });
    toolbar.querySelectorAll('.m-filter').forEach(sel => {
        sel.addEventListener('change', e => {
            state.filters[e.target.dataset.type] = e.target.value;
            state.offset = 0;
            load();
        });
    });

    tableArea.addEventListener('click', function delegated(e) {
        const pageBtn = e.target.closest('[data-page]');
        if (pageBtn) {
            state.offset = parseInt(pageBtn.dataset.page, 10);
            load();
        }
    });

    await load();
}

function renderMovieTable(content, data) {
    if (!data.movies.length) {
        content.innerHTML = `<div class="empty-state">No movies match the current search/filters. Try clearing them, or click <strong>Sync Library</strong> above to pull your library in.</div>${renderLegend()}`;
        return;
    }
    const rows = data.movies.map(m => `
        <tr class="clickable" data-ratingkey="${m.rating_key}">
            <td>${esc(m.title)} ${m.year ? `<span style="color:var(--text-muted)">(${m.year})</span>` : ''}</td>
            ${ASSET_TYPES.map(t => `<td>${statusEmoji(m[`${t}_status`])}</td>`).join('')}
            <td class="path" title="${esc(m.folder_path || '')}">${esc(m.display_path || m.folder_path || '')}</td>
            <td>${m.rating_key}</td>
        </tr>`).join('');

    const totalPages = Math.ceil(data.total / data.limit);
    const curPage = Math.floor(data.offset / data.limit) + 1;
    const pagination = totalPages > 1 ? `
        <div class="pagination">
            <button class="btn" ${data.offset === 0 ? 'disabled' : ''} data-page="${Math.max(0, data.offset - data.limit)}">← Prev</button>
            <span>Page ${curPage} of ${totalPages}</span>
            <button class="btn" ${curPage >= totalPages ? 'disabled' : ''} data-page="${data.offset + data.limit}">Next →</button>
        </div>` : '';

    content.innerHTML = `
        <table class="data-table">
            <thead><tr>
                <th>Title${sortArrow(data.sort, 'title', data.dir)}</th>
                ${ASSET_TYPES.map(t => `<th><img src="${ASSET_ICONS[t]}" class="asset-icon" alt="${esc(ASSET_LABELS[t])}" title="${esc(ASSET_LABELS[t])}"></th>`).join('')}
                <th>Path</th>
                <th>Plex ID${sortArrow(data.sort, 'rating_key', data.dir)}</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        ${pagination}
        ${renderLegend()}
    `;
    content.querySelectorAll('tr.clickable').forEach(tr => {
        tr.addEventListener('click', () => { location.hash = '#/movies/' + tr.dataset.ratingkey; });
    });
}

function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

/* ==========================================================================
   Movie detail
   ========================================================================== */

async function viewMovieDetail(content, toolbar, ratingKey) {
    const data = await api('movie_detail.php?ratingKey=' + encodeURIComponent(ratingKey));
    const m = data.movie;
    const thumbW = (await api('settings.php')).thumb_max_width || 100;

    toolbar.innerHTML = `
        <a class="btn" href="#/movies">← Back to Movies</a>
    `;

    // Logo/Square previews: pull the exact saved filename from history (same
    // source as the "Last changed via" line below) rather than assuming the
    // standard logo.png/square.jpg names, and confirm it's still actually on
    // disk before trying to render it.
    const successStatuses = ['new', 'updated', 'unchanged', 'kept_existing'];
    const logoChanged = [...data.history].find(h => h.asset_type === 'logo' && successStatuses.includes(h.status));
    const squareChanged = [...data.history].find(h => h.asset_type === 'square' && successStatuses.includes(h.status));
    const logoFile = logoChanged && data.files.includes(logoChanged.filename) ? logoChanged.filename : null;
    const squareFile = squareChanged && data.files.includes(squareChanged.filename) ? squareChanged.filename : null;
    const logoUrl = logoFile ? `api/image.php?ratingKey=${m.rating_key}&file=${encodeURIComponent(logoFile)}` : null;
    const squareUrl = squareFile ? `api/image.php?ratingKey=${m.rating_key}&file=${encodeURIComponent(squareFile)}` : null;

    const logoPreviewHtml = logoUrl ? `
        <div class="section-block">
            <h3 class="section-title" style="font-size:16px">Logo Preview</h3>
            <div class="logo-preview-grid">
                <div class="logo-preview-box">
                    <div class="frame light"><img src="${logoUrl}" alt="Logo on light background"></div>
                    <div class="label">Light Background</div>
                </div>
                <div class="logo-preview-box">
                    <div class="frame dark"><img src="${logoUrl}" alt="Logo on dark background"></div>
                    <div class="label">Dark Background</div>
                </div>
                ${squareUrl ? `
                <div class="logo-preview-box">
                    <div class="frame square-overlay">
                        <img class="square-bg" src="${squareUrl}" alt="Square Art">
                        <img class="logo-fg" src="${logoUrl}" alt="Logo over Square Art">
                    </div>
                    <div class="label">Over Square Art</div>
                </div>` : ''}
            </div>
        </div>` : '';

    const filesHtml = data.files.length
        ? `<div class="thumb-grid" style="--thumb-w:${thumbW}px">` + data.files.map(f => `
            <div class="thumb-item">
                <a href="api/image.php?ratingKey=${m.rating_key}&file=${encodeURIComponent(f)}&full=1" target="_blank">
                    <img src="api/image.php?ratingKey=${m.rating_key}&file=${encodeURIComponent(f)}" loading="lazy" alt="${esc(f)}">
                </a>
                <div class="fname">${esc(f)}</div>
            </div>`).join('') + `</div>`
        : '<div class="empty-state">No image files found in this folder.</div>';

    const pendingByType = {};
    data.pending.forEach(p => pendingByType[p.asset_type] = p);
    const ignoredByType = {};
    data.ignored.forEach(i => ignoredByType[i.asset_type] = i);

    const assetRows = ASSET_TYPES.map(t => {
        const lastChanged = [...data.history].find(h => h.asset_type === t && ['new', 'updated', 'unchanged', 'kept_existing'].includes(h.status));
        const isPending = pendingByType[t];
        const isIgnored = ignoredByType[t];
        return `
            <div class="setting-row">
                <div class="label">${ASSET_LABELS[t]}</div>
                <div class="control-wrap">
                    ${lastChanged ? `Last changed ${fmtDate(lastChanged.changed_at)} via ${esc(lastChanged.source)} ${statusBadge(lastChanged.status)}` : '<span style="color:var(--text-muted)">No successful save on record</span>'}
                    ${isPending ? `<div class="help warn">Needs attention: ${esc(isPending.reason)}</div>` : ''}
                    ${isIgnored ? `<div class="help">Ignored${isIgnored.note ? ': ' + esc(isIgnored.note) : ''}</div>` : ''}
                    <div class="pill-row">
                        <button class="btn" data-candidates="${t}">Find Candidates</button>
                        <button class="btn" data-upload="${t}">Upload…</button>
                        ${isIgnored
                            ? `<button class="btn" data-unignore="${t}">Un-ignore</button>`
                            : `<button class="btn" data-ignore="${t}">Ignore</button>`}
                    </div>
                    <input type="file" data-upload-input="${t}" accept="image/png,image/jpeg,image/webp" style="display:none">
                </div>
            </div>`;
    }).join('');

    content.innerHTML = `
        <h2 class="section-title">${esc(m.title)} ${m.year ? `(${m.year})` : ''}</h2>
        <div class="card section-block">
            <div class="setting-row">
                <div class="label">Path</div>
                <div class="control-wrap path" title="${esc(m.folder_path || '')}">${esc(m.display_path || m.folder_path || '—')}</div>
            </div>
            ${assetRows}
        </div>
        <div class="pill-row">
            <a class="btn btn-primary" href="#/diagnostics" id="detail-diag-link">Run Diagnostics on This Movie</a>
        </div>
        ${logoPreviewHtml}
        <div class="section-block">
            <h3 class="section-title" style="font-size:16px">Files in Folder</h3>
            ${filesHtml}
        </div>
        <div id="candidates-modal-root"></div>
    `;

    document.getElementById('detail-diag-link').addEventListener('click', () => {
        sessionStorage.setItem('diag_prefill_rk', String(m.rating_key));
    });

    content.querySelectorAll('[data-candidates]').forEach(btn => {
        btn.addEventListener('click', () => openCandidatesModal(m.rating_key, btn.dataset.candidates, () => viewMovieDetail(content, toolbar, ratingKey)));
    });
    content.querySelectorAll('[data-ignore]').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api('ignore.php', { method: 'POST', body: JSON.stringify({ ratingKey: m.rating_key, assetType: btn.dataset.ignore }) });
            toast('Marked as ignored.', 'success');
            viewMovieDetail(content, toolbar, ratingKey);
        });
    });
    content.querySelectorAll('[data-unignore]').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api('ignore.php?ratingKey=' + m.rating_key + '&assetType=' + btn.dataset.unignore, { method: 'DELETE' });
            toast('Un-ignored.', 'success');
            viewMovieDetail(content, toolbar, ratingKey);
        });
    });
    content.querySelectorAll('[data-upload]').forEach(btn => {
        const type = btn.dataset.upload;
        const input = content.querySelector(`[data-upload-input="${type}"]`);
        btn.addEventListener('click', () => input.click());
        input.addEventListener('change', async () => {
            if (!input.files.length) return;
            const fd = new FormData();
            fd.append('ratingKey', m.rating_key);
            fd.append('assetType', type);
            fd.append('file', input.files[0]);
            try {
                const r = await api('upload_override.php', { method: 'POST', body: fd });
                toast(`Saved ${ASSET_LABELS[type]} (${STATUS_LABELS[r.status] || r.status}).`, 'success');
                viewMovieDetail(content, toolbar, ratingKey);
            } catch (e) {
                toast(e.message, 'error');
            }
        });
    });
}

async function openCandidatesModal(ratingKey, assetType, onApplied) {
    const root = document.getElementById('candidates-modal-root');
    const showBgToggle = assetType === 'logo';
    root.innerHTML = `
        <div class="modal-backdrop">
            <div class="modal" id="cand-modal">
                <span class="close">&times;</span>
                <h3>Choose ${ASSET_LABELS[assetType]}</h3>
                ${showBgToggle ? `
                    <div class="pill-row" style="margin-top:-6px;">
                        <button class="btn" id="cand-bg-light">Light Background</button>
                        <button class="btn" id="cand-bg-dark">Dark Background</button>
                    </div>` : ''}
                <div id="cand-body"><div class="empty-state"><span class="spinner"></span> Searching Fanart.tv and TMDB…</div></div>
            </div>
        </div>`;
    const closeModal = () => { root.innerHTML = ''; };
    root.querySelector('.close').addEventListener('click', closeModal);
    root.querySelector('.modal-backdrop').addEventListener('click', e => { if (e.target.classList.contains('modal-backdrop')) closeModal(); });

    if (showBgToggle) {
        const modalEl = document.getElementById('cand-modal');
        document.getElementById('cand-bg-dark').addEventListener('click', () => modalEl.classList.add('bg-dark'));
        document.getElementById('cand-bg-light').addEventListener('click', () => modalEl.classList.remove('bg-dark'));
    }

    async function doIgnore() {
        try {
            await api('ignore.php', { method: 'POST', body: JSON.stringify({ ratingKey, assetType, note: 'No candidates found' }) });
            toast('Marked as ignored.', 'success');
            closeModal();
            onApplied();
        } catch (e) {
            toast(e.message, 'error');
        }
    }

    try {
        const data = await api(`candidates.php?ratingKey=${ratingKey}&assetType=${assetType}`);
        const body = document.getElementById('cand-body');
        let html = '';
        if (data.note) html += `<div class="banner banner-warn">${esc(data.note)}</div>`;
        if (!data.candidates.length) {
            html += `
                <div class="empty-state">
                    No candidate images found.
                    <div class="pill-row" style="justify-content:center;">
                        <button class="btn" id="cand-ignore">Ignore ${ASSET_LABELS[assetType]} for This Movie</button>
                    </div>
                </div>`;
        } else {
            html += '<div class="candidate-grid">' + data.candidates.slice(0, 24).map(c => `
                <div class="candidate-item" data-url="${esc(c.url)}" data-source="${esc(c.source)}">
                    <img src="${esc(c.url)}" loading="lazy">
                    <div class="meta">${esc(c.source)}${c.lang ? ' · ' + esc(c.lang) : ''}${c.likes != null ? ' · ♥' + c.likes : ''}</div>
                </div>`).join('') + '</div>';
        }
        body.innerHTML = html;
        document.getElementById('cand-ignore')?.addEventListener('click', doIgnore);
        body.querySelectorAll('.candidate-item').forEach(item => {
            item.addEventListener('click', async () => {
                try {
                    const r = await api('apply_candidate.php', {
                        method: 'POST',
                        body: JSON.stringify({ ratingKey, assetType, imageUrl: item.dataset.url, source: item.dataset.source }),
                    });
                    toast(`Saved ${ASSET_LABELS[assetType]} (${STATUS_LABELS[r.status] || r.status}).`, 'success');
                    closeModal();
                    onApplied();
                } catch (e) {
                    toast(e.message, 'error');
                }
            });
        });
    } catch (e) {
        document.getElementById('cand-body').innerHTML = `<div class="banner banner-danger">${esc(e.message)}</div>`;
    }
}

/* ==========================================================================
   Needs Review
   ========================================================================== */

async function viewReview(content, toolbar, query) {
    const state = {
        mode: 'review', // 'review' | 'ignored'
        q: '',
        sort: 'created_at',
        dir: 'desc',
        types: query && query.get('types') ? query.get('types').split(',').filter(t => ASSET_TYPES.includes(t)) : [],
    };

    function renderToolbar() {
        toolbar.innerHTML = `
            <span class="toolbar-title"><span class="icon-mask icon-mask-review"></span><span id="rv-title">Needs Review</span></span>
            <button class="btn" id="rv-tab-review" style="font-weight:700">Needs Review</button>
            <button class="btn" id="rv-tab-ignored">Ignored</button>
            <input type="search" id="rv-search" placeholder="Search movies…" style="width:220px" value="${esc(state.q)}">
            <select id="rv-sort">
                <option value="created_at" ${state.sort === 'created_at' ? 'selected' : ''}>Sort: ${state.mode === 'ignored' ? 'Ignored On' : 'Since'}</option>
                <option value="title" ${state.sort === 'title' ? 'selected' : ''}>Sort: Movie</option>
                <option value="rating_key" ${state.sort === 'rating_key' ? 'selected' : ''}>Sort: Plex ID</option>
            </select>
            <button class="btn" id="rv-dir">${state.dir === 'asc' ? '↑ Asc' : '↓ Desc'}</button>
            ${ASSET_TYPES.map(t => `
                <label class="checkbox" style="color:#c2c7cc; font-size:12px;">
                    <input type="checkbox" class="rv-type" value="${t}" ${state.types.includes(t) ? 'checked' : ''}> ${ASSET_LABELS[t]}
                </label>`).join('')}
        `;
        document.getElementById('rv-tab-review').style.opacity = state.mode === 'review' ? '1' : '0.6';
        document.getElementById('rv-tab-ignored').style.opacity = state.mode === 'ignored' ? '1' : '0.6';
        document.getElementById('rv-tab-review').addEventListener('click', () => { if (state.mode !== 'review') { state.mode = 'review'; renderToolbar(); load(); } });
        document.getElementById('rv-tab-ignored').addEventListener('click', () => { if (state.mode !== 'ignored') { state.mode = 'ignored'; renderToolbar(); load(); } });
        document.getElementById('rv-search').addEventListener('input', debounce(e => { state.q = e.target.value; load(); }, 300));
        document.getElementById('rv-sort').addEventListener('change', e => { state.sort = e.target.value; load(); });
        document.getElementById('rv-dir').addEventListener('click', () => {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            document.getElementById('rv-dir').textContent = state.dir === 'asc' ? '↑ Asc' : '↓ Desc';
            load();
        });
        toolbar.querySelectorAll('.rv-type').forEach(cb => {
            cb.addEventListener('change', () => {
                state.types = [...toolbar.querySelectorAll('.rv-type:checked')].map(c => c.value);
                load();
            });
        });
    }

    async function load() {
        content.innerHTML = '<div class="empty-state"><span class="spinner"></span> Loading…</div>';
        const params = new URLSearchParams({ limit: 200, q: state.q, sort: state.sort, dir: state.dir });
        if (state.types.length) params.set('types', state.types.join(','));
        const endpoint = state.mode === 'ignored' ? 'ignore.php' : 'pending_review.php';
        const data = await api(endpoint + '?' + params.toString());
        document.getElementById('rv-title').textContent = state.mode === 'ignored' ? `Ignored (${data.total})` : `Needs Review (${data.total})`;
        renderTable(data);
    }

    function renderTable(data) {
        if (!data.items.length) {
            content.innerHTML = `<div class="empty-state">${state.mode === 'ignored' ? 'Nothing is currently ignored.' : 'Nothing needs attention right now.'}</div>`;
            return;
        }
        const isIgnored = state.mode === 'ignored';
        const rows = data.items.map(i => `
            <tr>
                <td><a href="#/movies/${i.rating_key}">${esc(i.title)}</a> ${i.year ? `(${i.year})` : ''}</td>
                <td>${i.rating_key}</td>
                <td class="path" title="${esc(i.folder_path || '')}">${esc(i.display_path || i.folder_path || '')}</td>
                <td>${ASSET_LABELS[i.asset_type] || i.asset_type}</td>
                <td>${esc((isIgnored ? i.note : i.reason) || '')}</td>
                <td>${fmtDate(i.created_at)}</td>
                <td>
                    <button class="btn" data-review-candidates data-ratingkey="${i.rating_key}" data-type="${i.asset_type}">Find Candidates</button>
                    ${isIgnored
                        ? `<button class="btn" data-review-unignore data-ratingkey="${i.rating_key}" data-type="${i.asset_type}">Un-ignore</button>`
                        : ''}
                </td>
            </tr>`).join('');

        content.innerHTML = `
            <table class="data-table">
                <thead><tr>
                    <th>Movie${sortArrow(data.sort, 'title', data.dir)}</th>
                    <th>Plex ID${sortArrow(data.sort, 'rating_key', data.dir)}</th>
                    <th>Path</th><th>Asset</th><th>${isIgnored ? 'Note' : 'Reason'}</th>
                    <th>${isIgnored ? 'Ignored On' : 'Since'}${sortArrow(data.sort, 'created_at', data.dir)}</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
            <div id="candidates-modal-root"></div>
        `;

        content.querySelectorAll('[data-review-candidates]').forEach(btn => {
            btn.addEventListener('click', () => openCandidatesModal(btn.dataset.ratingkey, btn.dataset.type, load));
        });
        content.querySelectorAll('[data-review-unignore]').forEach(btn => {
            btn.addEventListener('click', async () => {
                await api('ignore.php?ratingKey=' + btn.dataset.ratingkey + '&assetType=' + btn.dataset.type, { method: 'DELETE' });
                toast('Un-ignored.', 'success');
                load();
            });
        });
    }

    renderToolbar();
    await load();
}

/* ==========================================================================
   Diagnostics / Test Query
   ========================================================================== */

async function viewDiagnostics(content, toolbar) {
    toolbar.innerHTML = `<span class="toolbar-title"><span class="icon-mask icon-mask-diagnostics"></span>Diagnostics</span>`;
    content.innerHTML = `
        <div class="card section-block">
            <p style="margin-top:0; color:var(--text-muted)">
                Look up one movie by Plex ID and see exactly what Plex reports, what Plex Art Manager can actually see on disk, and what user that process is running as.
                Use this to find things like "Would Create" showing for artwork that already exists.
            </p>
            <div style="display:flex; gap:8px;">
                <input type="number" id="d-ratingkey" placeholder="Plex ID, e.g. 72749" style="width:220px">
                <button class="btn btn-primary" id="d-run">Run Test Query</button>
            </div>
            <div class="help" style="margin-top:14px">Find a Plex ID from the Movies list (click a movie — it's in the URL as #/movies/&lt;ratingKey&gt;) or from Plex's own "Get Info" / XML view.</div>
        </div>
        <div id="d-results"></div>
    `;

    document.getElementById('d-run').addEventListener('click', runDiagnostics);
    document.getElementById('d-ratingkey').addEventListener('keydown', e => { if (e.key === 'Enter') runDiagnostics(); });

    const prefill = sessionStorage.getItem('diag_prefill_rk');
    if (prefill) {
        sessionStorage.removeItem('diag_prefill_rk');
        document.getElementById('d-ratingkey').value = prefill;
        runDiagnostics();
    }
}

async function runDiagnostics() {
    const ratingKey = document.getElementById('d-ratingkey').value.trim();
    const box = document.getElementById('d-results');
    if (!ratingKey) { toast('Enter a rating key first', 'error'); return; }

    box.innerHTML = '<div class="empty-state"><span class="spinner"></span> Querying Plex and the filesystem…</div>';
    let data;
    try {
        data = await api('diagnostics.php?ratingKey=' + encodeURIComponent(ratingKey));
    } catch (e) {
        box.innerHTML = `<div class="banner banner-danger">${esc(e.message)}</div>`;
        return;
    }

    const notesHtml = data.notes.map(n => `<div class="banner banner-warn">${esc(n)}</div>`).join('');

    const assetRows = ASSET_TYPES.map(t => {
        const a = data.filesystem.assets[t];
        const urlKnown = data.plex.imageUrls[t] ? '✓ Plex has one' : '✗ Plex has none';
        return `
            <tr>
                <td>${ASSET_LABELS[t]}</td>
                <td>${urlKnown}</td>
                <td>${a.exists ? `✓ ${esc(a.foundFilename)} (${Math.round(a.sizeBytes / 1024)} KB, ${fmtDate(a.modifiedAt)})` : '✗ not found on disk'}</td>
            </tr>`;
    }).join('');

    box.innerHTML = `
        ${notesHtml}
        <div class="card section-block">
            <h3 style="margin-top:0">Plex says</h3>
            <table class="data-table">
                <tbody>
                    <tr><td style="width:200px"><strong>Title</strong></td><td>${esc(data.plex.title)}</td></tr>
                    <tr><td><strong>Raw file path</strong></td><td class="path">${esc(data.plex.rawFilePath || '—')}</td></tr>
                    <tr><td><strong>Resolved folder</strong></td><td class="path">${esc(data.plex.resolvedFolder || '—')}</td></tr>
                    <tr><td><strong>Displayed Path</strong></td><td class="path">${esc(data.plex.displayPath || '—')}</td></tr>
                    <tr><td><strong>TMDB ID</strong></td><td>${esc(data.plex.tmdbId ?? '—')}</td></tr>
                    <tr><td><strong>IMDB ID</strong></td><td>${esc(data.plex.imdbId ?? '—')}</td></tr>
                </tbody>
            </table>
            ${data.mappingNote ? `<div class="banner banner-warn" style="margin-top:14px">${esc(data.mappingNote)}</div>` : ''}
        </div>
        <div class="card section-block">
            <h3 style="margin-top:0">Plex Art Manager's process sees</h3>
            <table class="data-table">
                <tbody>
                    <tr><td style="width:200px"><strong>Folder exists</strong></td><td>${data.filesystem.folderExists ? '✓ yes' : '✗ no'}</td></tr>
                    <tr><td><strong>Folder readable</strong></td><td>${data.filesystem.folderReadable ? '✓ yes' : '✗ no'}</td></tr>
                    <tr><td><strong>Folder writable</strong></td><td>${data.filesystem.folderWritable ? '✓ yes' : '✗ no'}</td></tr>
                    <tr><td><strong>PHP process user</strong></td><td>${esc(data.processUser.processUser || '(posix extension unavailable)')}</td></tr>
                    <tr><td><strong>Script file owner</strong></td><td>${esc(data.processUser.scriptOwner || '—')}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card section-block">
            <h3 style="margin-top:0">Per-asset comparison</h3>
            <table class="data-table">
                <thead><tr><th>Asset</th><th>Plex has a source image?</th><th>File on disk</th></tr></thead>
                <tbody>${assetRows}</tbody>
            </table>
        </div>
        ${data.filesystem.otherFiles.length ? `
        <div class="card section-block">
            <h3 style="margin-top:0">All image files in folder</h3>
            <div class="pill-row">${data.filesystem.otherFiles.map(f => `<span class="pill">${esc(f)}</span>`).join('')}</div>
        </div>` : ''}
    `;
}

/* ==========================================================================
   Log View
   ========================================================================== */

async function viewLogs(content, toolbar) {
    const state = { level: '', offset: 0, limit: 50 };

    toolbar.innerHTML = `
        <span class="toolbar-title"><span class="icon-mask icon-mask-logs"></span>Logs</span>
        <select id="l-level">
            <option value="">All levels</option>
            <option value="debug">Debug</option>
            <option value="info">Info</option>
            <option value="warn">Warn</option>
            <option value="error">Error</option>
        </select>
        <button class="btn" id="l-refresh">Refresh</button>
    `;

    content.innerHTML = '<div id="logs-area"></div>';
    const logsArea = document.getElementById('logs-area');

    async function load() {
        logsArea.innerHTML = '<div class="empty-state"><span class="spinner"></span> Loading…</div>';
        const params = new URLSearchParams({ limit: state.limit, offset: state.offset });
        if (state.level) params.set('level', state.level);
        const data = await api('logs.php?' + params.toString());
        renderLogTable(logsArea, data);
    }

    function renderLogTable(box, data) {
        if (!data.logs.length) {
            box.innerHTML = `<div class="empty-state">No log entries${state.level ? ' at this level' : ''} yet. ${state.level === '' ? 'Debug-level entries only appear once Debug Mode is turned on in Settings.' : ''}</div>`;
            return;
        }
        const rows = data.logs.map(l => `
            <tr>
                <td style="white-space:nowrap">${fmtDate(l.created_at)}</td>
                <td>${logLevelBadge(l.level)}</td>
                <td>${l.job_id ? '#' + l.job_id : ''}</td>
                <td>${esc(l.message)}</td>
            </tr>`).join('');

        const totalPages = Math.ceil(data.total / data.limit);
        const curPage = Math.floor(data.offset / data.limit) + 1;
        const pagination = totalPages > 1 ? `
            <div class="pagination">
                <button class="btn" ${data.offset === 0 ? 'disabled' : ''} data-page="${Math.max(0, data.offset - data.limit)}">← Prev</button>
                <span>Page ${curPage} of ${totalPages}</span>
                <button class="btn" ${curPage >= totalPages ? 'disabled' : ''} data-page="${data.offset + data.limit}">Next →</button>
            </div>` : '';

        box.innerHTML = `
            <table class="data-table">
                <thead><tr><th>Time</th><th>Level</th><th>Job</th><th>Message</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
            ${pagination}
        `;
    }

    document.getElementById('l-level').addEventListener('change', e => { state.level = e.target.value; state.offset = 0; load(); });
    document.getElementById('l-refresh').addEventListener('click', () => load());
    logsArea.addEventListener('click', function delegated(e) {
        const pageBtn = e.target.closest('[data-page]');
        if (pageBtn) {
            state.offset = parseInt(pageBtn.dataset.page, 10);
            load();
        }
    });

    await load();
}

function logLevelBadge(level) {
    const cls = { debug: 'badge-none', info: 'badge-unchanged', warn: 'badge-kept_existing', error: 'badge-failed' }[level] || 'badge-none';
    return `<span class="badge ${cls}">${esc(level)}</span>`;
}

/* ==========================================================================
   Help
   ========================================================================== */

async function viewHelp(content, toolbar) {
    toolbar.innerHTML = `
        <span class="toolbar-title"><span class="icon-mask icon-mask-help"></span>Help</span>
        <input type="search" id="help-search" placeholder="Search help…" style="width:260px">
    `;
    content.innerHTML = '<div class="empty-state"><span class="spinner"></span> Loading…</div>';

    let sections = [];
    try {
        sections = (await api('help.php')).sections;
    } catch (e) {
        content.innerHTML = `<div class="banner banner-danger">${esc(e.message)}</div>`;
        return;
    }

    content.innerHTML = sections.map(s => {
        const plainText = (s.title + ' ' + s.body.replace(/<[^>]+>/g, ' ')).toLowerCase();
        return `
        <div class="help-section" data-help-id="${s.id}" data-help-text="${esc(plainText)}">
            <h3>${esc(s.title)}</h3>
            ${s.body}
        </div>`;
    }).join('');

    document.getElementById('help-search').addEventListener('input', debounce(e => {
        const q = e.target.value.trim().toLowerCase();
        content.querySelectorAll('.help-section').forEach(sectionEl => {
            const matches = q === '' || (sectionEl.dataset.helpText || '').includes(q);
            sectionEl.classList.toggle('hidden', !matches);
        });
    }, 150));
}

/* ==========================================================================
   Settings
   ========================================================================== */

async function viewSettings(content, toolbar) {
    toolbar.innerHTML = `<span class="toolbar-title"><span class="icon-mask icon-mask-settings"></span>Settings</span>`;
    const s = await api('settings.php');

    let fmRows;
    try {
        const parsed = JSON.parse(s.folder_mappings_json || '[]');
        fmRows = (Array.isArray(parsed) && parsed.length)
            ? parsed.map(r => ({ plexPath: r.plexPath || '', localPath: r.localPath || '', displayPath: r.displayPath || '' }))
            : [{ plexPath: '', localPath: '', displayPath: '' }];
    } catch (e) {
        fmRows = [{ plexPath: '', localPath: '', displayPath: '' }];
    }

    content.innerHTML = `
        <div class="card section-block">
            <h3 style="margin-top:0">Plex Connection</h3>
            <div class="setting-row">
                <div class="label">Plex URL</div>
                <div class="control-wrap">
                    <input type="text" id="s-plex-url" value="${esc(s.plex_url)}" placeholder="http://10.0.216.12:32400">
                    <div class="help">Base URL of your Plex Media Server, no trailing slash.</div>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">Plex Token</div>
                <div class="control-wrap">
                    <input type="password" id="s-plex-token" value="${esc(s.plex_token)}" placeholder="Enter to change">
                    <div class="help">${s._configured.plex ? 'A token is currently saved.' : 'Not set yet.'} Stored in the local database only, never sent anywhere but your Plex server.</div>
                </div>
            </div>
        </div>

        <div class="card section-block">
            <h3 style="margin-top:0">Candidate Image Sources</h3>
            <div class="setting-row">
                <div class="label">Fanart.tv API Key</div>
                <div class="control-wrap">
                    <input type="password" id="s-fanart" value="${esc(s.fanart_api_key)}" placeholder="Enter to change">
                    <div class="help">${s._configured.fanart ? 'A key is currently saved.' : 'Optional — needed for candidate image suggestions.'}</div>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">TMDB API Key</div>
                <div class="control-wrap">
                    <input type="password" id="s-tmdb" value="${esc(s.tmdb_api_key)}" placeholder="Enter to change">
                    <div class="help">${s._configured.tmdb ? 'A key is currently saved.' : 'Optional — needed for candidate image suggestions.'} Neither service has a dedicated "square art" category for movies, so Square Art candidates will often be empty regardless.</div>
                </div>
            </div>
        </div>

        <div class="card section-block">
            <h3 style="margin-top:0">Display &amp; Batching</h3>
            <div class="setting-row">
                <div class="label">Thumbnail Max Width</div>
                <div class="control-wrap">
                    <input type="number" id="s-thumbwidth" value="${esc(s.thumb_max_width)}" min="20" max="2000" style="width:120px"> px
                    <div class="help">Images on the movie detail page are resized to this width and cached.</div>
                </div>
            </div>
            <div class="setting-row">
                <div class="label">Default Batch Size</div>
                <div class="control-wrap">
                    <input type="number" id="s-batchsize" value="${esc(s.batch_default_size)}" min="1" max="100" style="width:120px">
                </div>
            </div>
        </div>

        <div class="card section-block">
            <h3 style="margin-top:0">Folder Mapping</h3>
            <p style="color:var(--text-muted); font-size:13px; margin-top:0;">
                Only needed if Plex Art Manager sees a different filesystem path than Plex reports (e.g. Plex running in
                Docker, or reached through a different mount point) or you'd like a friendlier label than the real
                path in the UI. <strong>Path in Plex</strong> and <strong>Local Path</strong> can be identical if
                there's no actual path difference - <strong>Display Path</strong> alone still works to relabel that
                folder. Add one row per independent mount root.
            </p>
            <div id="fm-rows"></div>
            <button class="btn" id="fm-add" type="button">+ Add Folder</button>
        </div>

        <div class="card section-block">
            <h3 style="margin-top:0">Logging</h3>
            <div class="setting-row">
                <div class="label">Debug Mode</div>
                <div class="control-wrap">
                    <label class="checkbox"><input type="checkbox" id="s-debug-mode" ${s.debug_mode === '1' ? 'checked' : ''}> Debug Mode</label>
                    <div class="help">Adds per-chunk detail from Sync/Batch jobs to the <a href="#/logs">Logs</a> screen. Off by default to keep the log small.</div>
                </div>
            </div>
        </div>

        <button class="btn btn-primary" id="s-save">Save Changes</button>
    `;

    function renderFmRows() {
        const box = document.getElementById('fm-rows');
        box.innerHTML = fmRows.map((row, i) => `
            <div class="setting-row">
                <div class="label">Folder ${i + 1}</div>
                <div class="control-wrap" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <div>
                        <div class="help" style="margin:0 0 4px;">Path in Plex</div>
                        <input type="text" class="fm-plex" data-idx="${i}" value="${esc(row.plexPath)}" placeholder="/Volumes/Plex Media/Feature Films" style="width:260px">
                    </div>
                    <div>
                        <div class="help" style="margin:0 0 4px;">Local Path</div>
                        <input type="text" class="fm-local" data-idx="${i}" value="${esc(row.localPath)}" placeholder="/plex-movies1" style="width:200px">
                    </div>
                    <div>
                        <div class="help" style="margin:0 0 4px;">Display Path</div>
                        <input type="text" class="fm-display" data-idx="${i}" value="${esc(row.displayPath)}" placeholder="Feature Films" style="width:200px">
                    </div>
                    <button class="btn btn-danger fm-remove" type="button" data-idx="${i}">Remove</button>
                </div>
            </div>`).join('');

        box.querySelectorAll('.fm-plex').forEach(el => el.addEventListener('input', e => { fmRows[+e.target.dataset.idx].plexPath = e.target.value; }));
        box.querySelectorAll('.fm-local').forEach(el => el.addEventListener('input', e => { fmRows[+e.target.dataset.idx].localPath = e.target.value; }));
        box.querySelectorAll('.fm-display').forEach(el => el.addEventListener('input', e => { fmRows[+e.target.dataset.idx].displayPath = e.target.value; }));
        box.querySelectorAll('.fm-remove').forEach(el => el.addEventListener('click', () => {
            fmRows.splice(+el.dataset.idx, 1);
            renderFmRows();
        }));
    }
    renderFmRows();

    document.getElementById('fm-add').addEventListener('click', () => {
        fmRows.push({ plexPath: '', localPath: '', displayPath: '' });
        renderFmRows();
    });

    document.getElementById('s-save').addEventListener('click', async () => {
        try {
            const folderMappings = fmRows.filter(r => r.plexPath.trim() || r.localPath.trim() || r.displayPath.trim());
            await api('settings.php', {
                method: 'POST',
                body: JSON.stringify({
                    plex_url: document.getElementById('s-plex-url').value.trim(),
                    plex_token: document.getElementById('s-plex-token').value,
                    fanart_api_key: document.getElementById('s-fanart').value,
                    tmdb_api_key: document.getElementById('s-tmdb').value,
                    thumb_max_width: document.getElementById('s-thumbwidth').value,
                    batch_default_size: document.getElementById('s-batchsize').value,
                    folder_mappings_json: JSON.stringify(folderMappings),
                    debug_mode: document.getElementById('s-debug-mode').checked ? '1' : '0',
                }),
            });
            toast('Settings saved.', 'success');
            viewSettings(content, toolbar);
        } catch (e) {
            toast(e.message, 'error');
        }
    });
}

            (function () {
                'use strict';

                // ── State Variables ──
                let isAdminState = false;
                let currentAdminName = '';
                let playersCache = [];
                let openingsDatabase = {};
                // Derived from openingsDatabase once it loads (see init()) — maps a bare
                // ECO code ("B01") to a real opening name ("Scandinavian Defense"), for
                // PGNs that carry an [ECO] tag but no [Opening] tag.
                let ecoNameIndex = {};

                // The opening entry for a position, or null. The database writes FEN's
                // en-passant field only when an en-passant capture is actually possible ("-"
                // otherwise), while chess.mjs writes it after every double pawn push — so a
                // plain four-field lookup missed the position after 1.e4, 1...e5, 1.d4 and
                // every other double push. Tries the exact key, then the same position with "-".
                function openingAt(fen) {
                    const [board, side, castling, ep = '-'] = String(fen).split(' ');
                    const key = `${board} ${side} ${castling}`;
                    return openingsDatabase[`${key} ${ep}`] || openingsDatabase[`${key} -`] || null;
                }
                let matchesCache = [];
                let tokensCache = [];
                let adminsCache = [];
                let galleryAdminData = [];
                let pendingUploadGalleryId = null;
                let newsletterPostsCache = [];
                let pendingPostImageId = null;
                let playerIdMap = {};
                let currentMatchFilter = 'all';
                let activePlayerUsername = null;

                // ── Icons ──
                // Same set/style as templates/_icons.html.twig's macro (24x24, 2px
                // stroke, round caps) — kept as a separate copy here since app.js is
                // a plain static asset, not Twig-processed, so it can't import that
                // file directly.
                const ICONS = {
                    key: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m10.5 12.5 9-9M17 4l3 3M14 7l3 3"/></svg>',
                    search: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
                    refresh: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>',
                    plus: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
                    pencil: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>',
                    trash: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="m19 6-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>',
                    trophy: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v4a4 4 0 0 1-8 0Z"/><path d="M8 5H5a3 3 0 0 0 3 4M16 5h3a3 3 0 0 1-3 4"/><path d="M12 12v4"/><path d="M9 20h6"/><path d="M10 16h4l1 4H9Z"/></svg>',
                    shield: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5Z"/></svg>',
                    ban: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/></svg>',
                    clipboard: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 10h6M9 14h6M9 18h3"/></svg>',
                    copy: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
                    book: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5v-17Z"/><path d="M4 19a2.5 2.5 0 0 1 2.5-2.5H20"/></svg>',
                    crown: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18h16M5 18 3 8l5 4 4-7 4 7 5-4-2 10"/></svg>',
                    link: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 15 15 9"/><path d="M11 6l1-1a4 4 0 1 1 6 6l-1 1"/><path d="M13 18l-1 1a4 4 0 1 1-6-6l1-1"/></svg>',
                    zap: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6Z"/></svg>',
                    pawn: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="3"/><path d="M9 12h6l1 3H8Z"/><path d="M7 20h10l-1-4H8Z"/></svg>',
                    clock: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg>',
                    calendar: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
                    pin: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-7.4 7-12a7 7 0 1 0-14 0c0 4.6 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
                    monitor: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
                    cloud: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18a4 4 0 0 1-.5-7.97A5.5 5.5 0 0 1 17 8.5 4.5 4.5 0 0 1 16.5 18Z"/></svg>',
                    chart: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20V10M12 20V4M20 20v-7"/></svg>',
                    stopwatch: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 13v-4M9 2h6M18 6l1.5-1.5"/></svg>',
                    // fill is set via inline style (not the fill="" presentation
                    // attribute) because svg.icon's stylesheet rule sets fill:none
                    // for the outline icon set — a stylesheet rule beats a
                    // presentation attribute, which was silently making both of
                    // these render as an unfilled outline in the same color.
                    pieceWhite: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" style="stroke:currentColor;fill:#fff"><rect x="5" y="5" width="14" height="14" rx="3"/></svg>',
                    pieceBlack: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true" style="stroke:currentColor;fill:#111"><rect x="5" y="5" width="14" height="14" rx="3"/></svg>',
                    image: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
                    filetext: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h7l4 4v16H7Z"/><path d="M14 2v4h4"/><path d="M9.5 13h5M9.5 16.5h5"/></svg>',
                    eye: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
                    eyeoff: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 5.2A10.7 10.7 0 0 1 12 5c6.5 0 10 7 10 7a15.6 15.6 0 0 1-3.4 4.3M6.6 6.6C4 8.3 2 12 2 12s3.5 7 10 7a10.4 10.4 0 0 0 4-.8"/><path d="M9.5 9.7A3 3 0 0 0 14.3 14.4"/></svg>',
                    pause: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5v14M15 5v14"/></svg>',
                    play: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4l12 8-12 8Z"/></svg>',
                    stop: '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>',
                };

                // ── DOM Helpers ──
                const $ = sel => document.querySelector(sel);
                const $$ = sel => document.querySelectorAll(sel);

                function escHtml(str) {
                    if (str === null || str === undefined) return '';
                    return String(str)
                        .replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;")
                        .replace(/'/g, "&#039;");
                }

                // A string argument for JS inside an HTML attribute (onclick="fn(${jsStr(x)})").
                // escHtml alone isn't enough there: the browser decodes &#039; back to ' before
                // running the handler, so '${escHtml(x)}' lets a quote in x end the string —
                // a username or VRChat display name like  x');alert(1);//  would run as code.
                // JSON.stringify makes a valid JS literal; escHtml then keeps it inside the attribute.
                function jsStr(value) {
                    return escHtml(JSON.stringify(String(value ?? '')));
                }

                // ── Toast Notifications ──
                function toast(message, type = 'info') {
                    const container = $('#toastContainer');
                    const el = document.createElement('div');
                    el.className = `toast toast--${type}`;
                    el.textContent = message;
                    container.appendChild(el);
                    setTimeout(() => {
                        el.remove();
                    }, 4000);
                }

                // ── API Fetch Wrapper ──
                async function apiCall(url, options = {}) {
                    options.headers = options.headers || {};
                    try {
                        const response = await fetch(url, options);
                        const data = await response.json();
                        if (!response.ok) {
                            throw new Error(data.error || `HTTP ${response.status}`);
                        }
                        return data;
                    } catch (err) {
                        throw err;
                    }
                }

                // ── Auth Status Check ──
                async function checkAuthStatus() {
                    try {
                        const res = await apiCall('/api/auth/status');
                        if (res.username) {
                            currentAdminName = res.username;
                        }
                        setAdminState(res.authenticated === true);
                    } catch (e) {
                        setAdminState(false);
                    }
                }

                function setAdminState(isAdmin) {
                    isAdminState = isAdmin;
                    if (isAdmin) {
                        document.body.classList.add('is-admin');
                        $('#authBadge').className = 'auth-badge auth-badge--admin admin-only';
                        $('#authBadge').innerHTML = `${ICONS.crown} Admin Mode`;
                        $('#currentAdminName').textContent = currentAdminName;
                        fetchTokens(true);
                        fetchAdmins(true);
                        fetchGalleryPhotos(true);
                        fetchNewsletterPosts(true);
                    } else {
                        document.body.classList.remove('is-admin');
                        $('#authBadge').className = 'auth-badge admin-only';
                        $('#authBadge').textContent = '';
                    }
                    // Re-render lists so admin-only buttons (delete) appear/disappear immediately
                    renderMatches();
                    renderAnalyses();
                }

                // ── Initial data load (also doubles as a server/DB reachability check —
                //    failures surface via toast only, no persistent status UI). ──
                async function checkConnection() {
                    try {
                        await checkAuthStatus();
                        const data = await apiCall('/api/players');
                        if (data.success) {
                            playersCache = data.players || [];
                            buildPlayerIdMap();
                            $('#playerCount').textContent = data.count || playersCache.length;
                            $('#adminStatPlayers').textContent = playersCache.length;
                            renderLeaderboard();
                            await fetchMatches(true);
                            if (typeof fetchAnalyses === 'function') {
                                await fetchAnalyses(true);
                            }
                        } else {
                            toast('Gagal memuat data pemain', 'error');
                        }
                    } catch (e) {
                        toast('Gagal terhubung ke database: ' + e.message, 'error');
                    }
                }

                // ── Build ID Map ──
                function buildPlayerIdMap() {
                    playerIdMap = {};
                    playersCache.forEach(p => {
                        playerIdMap[p.id] = p.username;
                    });

                    const datalist = $('#playerNamesList');
                    if (datalist) {
                        datalist.innerHTML = '';
                        playersCache.forEach(p => {
                            const option = document.createElement('option');
                            option.value = p.username;
                            datalist.appendChild(option);
                        });
                    }
                }

                // ── Fetch Models ──
                async function fetchPlayers(silent = false) {
                    try {
                        const data = await apiCall('/api/players');
                        if (data.success) {
                            playersCache = data.players || [];
                            buildPlayerIdMap();
                            $('#playerCount').textContent = data.count || playersCache.length;
                            $('#adminStatPlayers').textContent = playersCache.length;
                            renderLeaderboard($('#playerSearch') ? $('#playerSearch').value : '');
                            renderNameConflicts();
                            if (!silent) toast('Leaderboard diperbarui', 'success');
                        }
                    } catch (e) {
                        if (!silent) toast('Gagal mengambil data pemain: ' + e.message, 'error');
                    }
                }

                // ── Duplicate players (blocked VRChat renames) ──
                // A linked player whose VRChat display name is currently held by a different
                // player row: AvatarRefresher::syncUsername() reports that as a 'conflict' and
                // skips the rename on every refresh, so it needs resolving by hand once.
                // Derived from the player list rather than from a refresh response, so the
                // card is accurate on page load too, not only right after a refresh.
                //
                // Matched case-sensitively and exactly, like the server's own
                // PlayerRepository::isUsernameTaken(), so this never shows a conflict the
                // backend wouldn't also see.
                function nameConflicts() {
                    const byUsername = new Map(playersCache.map(p => [p.username, p]));
                    const out = [];
                    for (const player of playersCache) {
                        if (!player.vrchat_user_id) continue;
                        const target = (player.vrchat_display_name || '').trim();
                        if (!target || target === player.username) continue;
                        const duplicate = byUsername.get(target);
                        if (duplicate && duplicate.username !== player.username) out.push({ player, target, duplicate });
                    }
                    return out;
                }

                function renderNameConflicts() {
                    const card = $('#nameConflictCard');
                    const list = $('#nameConflictList');
                    if (!card || !list) return;

                    const conflicts = nameConflicts();
                    card.style.display = conflicts.length ? 'block' : 'none';
                    if (!conflicts.length) return;

                    list.innerHTML = `
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>Pemain tertaut</th>
                                <th>Nama VRChat</th>
                                <th>Duplikat yang akan digabung</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>${conflicts.map(({ player, target, duplicate }) => {
                            // Both linked, to different VRChat accounts: two different people
                            // who happen to want the same name (a swap, or someone taking a
                            // released one), not a duplicate. The server refuses this with a
                            // 409, so don't offer a button that can only fail.
                            const differentPeople = duplicate.vrchat_user_id && duplicate.vrchat_user_id !== player.vrchat_user_id;
                            return `
                            <tr>
                                <td><strong>${escHtml(player.username)}</strong>
                                    <div class="stat-value" style="font-size:0.75rem;">${player.games} match · ${player.rating}</div></td>
                                <td><span class="card__badge card__badge--orange">${escHtml(target)}</span></td>
                                <td><strong>${escHtml(duplicate.username)}</strong>
                                    <div class="stat-value" style="font-size:0.75rem;">${duplicate.games} match · ${duplicate.rating}${duplicate.vrchat_user_id ? ' · tertaut' : ''}</div></td>
                                <td>${differentPeople
                                    ? `<span class="stat-value" style="font-size:0.75rem;" title="Keduanya tertaut ke akun VRChat yang berbeda, jadi bukan duplikat. Putuskan tautan salah satu lewat Edit Pemain kalau memang perlu digabung.">Tertaut ke akun VRChat lain — bukan duplikat</span>`
                                    : `<button class="btn btn--danger btn--xs"
                                        onclick="window.appMergePlayers(${jsStr(duplicate.username)}, ${jsStr(player.username)})">
                                        ${ICONS.swords} Gabungkan</button>`}</td>
                            </tr>`;
                        }).join('')}</tbody>
                    </table>`;
                }

                window.appMergePlayers = async function (from, into) {
                    const dup = playersCache.find(p => p.username === from);
                    if (!confirm(
                        `Gabungkan '${from}' ke '${into}'?\n\n` +
                        `${dup ? dup.games : '?'} match milik '${from}' akan dipindahkan ke '${into}', ` +
                        `nama '${from}' disimpan sebagai riwayat, lalu '${from}' dihapus dan seluruh rating dihitung ulang.\n\n` +
                        'Tidak bisa dibatalkan.'
                    )) return;

                    try {
                        const res = await apiCall('/api/admin/players/merge', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ from, into })
                        });
                        if (res.success) {
                            toast(res.message || `'${from}' digabungkan ke '${into}'.`, 'success');
                            await fetchPlayers(true);
                            await fetchMatches(true);
                        } else {
                            toast(res.error || 'Gagal menggabungkan pemain', 'error');
                        }
                    } catch (e) {
                        toast('Gagal menggabungkan pemain: ' + e.message, 'error');
                    }
                };

                async function fetchMatches(silent = false) {
                    try {
                        const data = await apiCall('/api/matches');
                        if (data.success) {
                            matchesCache = data.matches || [];
                            $('#matchCount').textContent = data.count || matchesCache.length;
                            $('#adminStatMatches').textContent = matchesCache.length;
                            renderMatches();
                            if (!silent) toast('Riwayat pertandingan diperbarui', 'success');
                        }
                    } catch (e) {
                        console.error("fetchMatches Error:", e);
                        if (!silent) toast('Gagal mengambil data pertandingan: ' + e.message, 'error');
                    }
                }

                async function fetchTokens(silent = false) {
                    if (!isAdminState) return;
                    try {
                        const data = await apiCall('/api/admin/tokens');
                        if (data.success) {
                            tokensCache = data.tokens || [];
                            $('#adminStatTokens').textContent = tokensCache.length;
                            renderTokens();
                            if (!silent) toast('Daftar API Token diperbarui', 'success');
                        }
                    } catch (e) {
                        if (!silent) toast('Gagal mengambil token: ' + e.message, 'error');
                    }
                }

                async function fetchAdmins(silent = false) {
                    if (!isAdminState) return;
                    try {
                        const data = await apiCall('/api/admin/admins');
                        if (data.success) {
                            adminsCache = data.admins || [];
                            $('#adminStatAdmins').textContent = adminsCache.length;
                            renderAdmins();
                        }
                    } catch (e) {
                        if (!silent) toast('Gagal mengambil data admin: ' + e.message, 'error');
                    }
                }

                async function fetchGalleryPhotos(silent = false) {
                    if (!isAdminState) return;
                    try {
                        const data = await apiCall('/api/admin/gallery/photos');
                        if (data.success) {
                            galleryAdminData = data.galleries || [];
                            renderGalleryAdmin();
                            if (!silent) toast('Daftar galeri VRChat diperbarui', 'success');
                        }
                    } catch (e) {
                        if (!silent) toast('Gagal mengambil galeri VRChat: ' + e.message, 'error');
                    }
                }

                async function fetchNewsletterPosts(silent = false) {
                    if (!isAdminState) return;
                    try {
                        const data = await apiCall('/api/admin/newsletter/posts');
                        if (data.success) {
                            newsletterPostsCache = data.posts || [];
                            renderNewsletterAdmin();
                            if (!silent) toast('Daftar post newsletter diperbarui', 'success');
                        }
                    } catch (e) {
                        if (!silent) toast('Gagal mengambil post newsletter: ' + e.message, 'error');
                    }
                }

                // ── Render Views ──
                function renderTokens() {
                    const container = $('#tokensContainer');
                    if (tokensCache.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.key}</div>
                            <div class="empty-state__text">Belum ada API Token</div>
                        </div>`;
                        return;
                    }

                    let html = `
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>Nama Token</th>
                                <th>Status</th>
                                <th>API Token Secret</th>
                                <th>Tgl Dibuat</th>
                                <th>Terakhir Dipakai</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>`;

                    tokensCache.forEach(t => {
                        const isActive = t.is_active !== false && t.is_active !== "0";
                        const statusTag = isActive ? `<span class="card__badge card__badge--green">Aktif</span>` : `<span class="card__badge card__badge--red">Tidak Aktif</span>`;
                        html += `
                        <tr>
                            <td><strong>${escHtml(t.name)}</strong></td>
                            <td>${statusTag}</td>
                            <td>
                                <span class="token-code">${escHtml(t.token)}</span>
                                <button class="btn btn--icon btn--xs" onclick="navigator.clipboard.writeText(${jsStr(t.token)}); alert('Token berhasil di-copy!');" title="Copy Token">${ICONS.copy}</button>
                            </td>
                            <td><span class="stat-value">${escHtml(t.created_at)}</span></td>
                            <td><span class="stat-value">${escHtml(t.last_used)}</span></td>
                            <td>
                                <button class="btn btn--icon btn--xs match-actions-btn" onclick="window.appOpenEditToken(${jsStr(t.id)}, ${jsStr(t.name)}, ${isActive})" title="Edit Token">${ICONS.pencil}</button>
                                <button class="btn btn--danger btn--xs match-actions-btn" onclick="window.appRevokeToken(${jsStr(t.id)})" title="Cabut Token">${ICONS.trash}</button>
                            </td>
                        </tr>`;
                    });

                    html += '</tbody></table>';
                    container.innerHTML = html;
                }

                function renderAdmins() {
                    const container = $('#adminsContainer');
                    if (adminsCache.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.shield}</div>
                            <div class="empty-state__text">Belum ada Admin User</div>
                        </div>`;
                        return;
                    }

                    let html = `
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Tgl Dibuat</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>`;

                    adminsCache.forEach(a => {
                        const isSelf = a.username === currentAdminName;
                        const meTag = isSelf ? ` <span class="card__badge card__badge--purple">You</span>` : '';

                        html += `
                        <tr>
                            <td><strong>${escHtml(a.username)}</strong>${meTag}</td>
                            <td><span class="stat-value">${escHtml(a.created_at)}</span></td>
                            <td>
                                <button class="btn btn--icon btn--xs match-actions-btn" onclick="window.appOpenEditAdmin(${jsStr(a.username)})" title="Ubah Password">${ICONS.pencil} Password</button>
                                ${!isSelf ? `<button class="btn btn--danger btn--xs match-actions-btn" onclick="window.appDeleteAdmin(${jsStr(a.username)})" title="Hapus Admin">${ICONS.trash}</button>` : ''}
                            </td>
                        </tr>`;
                    });

                    html += '</tbody></table>';
                    container.innerHTML = html;
                }

                function renderGalleryAdmin() {
                    const container = $('#galleryAdminContainer');
                    if (galleryAdminData.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.image}</div>
                            <div class="empty-state__text">Belum ada galeri VRChat (atau VRCHAT_GROUP_ID belum dikonfigurasi)</div>
                        </div>`;
                        return;
                    }

                    let html = '';
                    galleryAdminData.forEach(g => {
                        html += `
                        <div class="gallery-admin-section">
                            <div class="gallery-admin-section__header">
                                <span class="gallery-admin-section__title">${escHtml(g.name)}</span>
                                <button type="button" class="btn btn--secondary btn--xs" onclick="window.appTriggerGalleryUpload(${jsStr(g.id)})">${ICONS.plus} Upload Gambar</button>
                            </div>`;

                        if (g.photos.length === 0) {
                            html += `<div class="gallery-admin-section__empty">Belum ada foto di galeri ini.</div>`;
                        } else {
                            html += '<div class="gallery-admin-grid">';
                            g.photos.forEach(p => {
                                html += `
                                <div class="gallery-admin-card${p.hidden ? ' gallery-admin-card--hidden' : ''}">
                                    <img class="gallery-admin-card__photo" src="${escHtml(p.src)}" alt="" loading="lazy">
                                    <div class="gallery-admin-card__meta">
                                        <span class="gallery-admin-card__date">${escHtml(relativeTime(p.createdAt))}</span>
                                    </div>
                                    <button class="btn ${p.hidden ? 'btn--secondary' : 'btn--danger'} btn--xs gallery-admin-card__toggle"
                                        onclick="window.${p.hidden ? 'appUnhideGalleryPhoto' : 'appHideGalleryPhoto'}(${jsStr(p.id)})">
                                        ${p.hidden ? ICONS.eye + ' Tampilkan' : ICONS.eyeoff + ' Sembunyikan'}
                                    </button>
                                </div>`;
                            });
                            html += '</div>';
                        }

                        html += '</div>';
                    });
                    container.innerHTML = html;
                }

                function renderNewsletterAdmin() {
                    const container = $('#newsletterAdminContainer');
                    if (newsletterPostsCache.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.filetext}</div>
                            <div class="empty-state__text">Belum ada post grup VRChat (atau VRCHAT_GROUP_ID belum dikonfigurasi)</div>
                        </div>`;
                        return;
                    }

                    let html = '<div class="newsletter-admin-list">';
                    newsletterPostsCache.forEach(p => {
                        const isPublic = p.visibility === 'public';
                        const visTag = isPublic
                            ? `<span class="card__badge card__badge--green">Publik</span>`
                            : `<span class="card__badge">Grup</span>`;
                        html += `
                        <div class="newsletter-admin-card">
                            ${p.imageUrl ? `<img class="newsletter-admin-card__photo" src="${escHtml(p.src)}" alt="" loading="lazy">` : ''}
                            <div class="newsletter-admin-card__body">
                                <div class="newsletter-admin-card__header">
                                    <strong>${escHtml(p.title)}</strong>
                                    ${visTag}
                                </div>
                                <p class="newsletter-admin-card__text">${escHtml(p.text)}</p>
                                <div class="newsletter-admin-card__meta">${escHtml(relativeTime(p.createdAt))}</div>
                            </div>
                            <div class="newsletter-admin-card__actions">
                                <button type="button" class="btn btn--secondary btn--xs" onclick="window.appTriggerPostImageUpload(${jsStr(p.id)})" title="Upload/Ganti Gambar">${ICONS.image}</button>
                                <button type="button" class="btn btn--secondary btn--xs" onclick="window.appOpenEditPost(${jsStr(p.id)})" title="Edit Post">${ICONS.pencil}</button>
                                <button type="button" class="btn btn--danger btn--xs" onclick="window.appDeletePost(${jsStr(p.id)})" title="Hapus Post">${ICONS.trash}</button>
                            </div>
                        </div>`;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                }

                // ── Profile / Formatting Helpers ──
                function getInitials(username) {
                    const s = String(username || '').trim();
                    if (!s) return '?';
                    const parts = s.split(/\s+/);
                    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
                    return (parts[0][0] + parts[1][0]).toUpperCase();
                }

                function hashHue(str) {
                    let hash = 0;
                    const s = String(str || '');
                    for (let i = 0; i < s.length; i++) {
                        hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
                    }
                    return hash % 360;
                }

                function avatarGradient(username) {
                    const hue = hashHue(username);
                    const hue2 = (hue + 40) % 360;
                    return `linear-gradient(135deg, hsl(${hue}, 70%, 55%), hsl(${hue2}, 70%, 45%))`;
                }

                // If an <img> avatar 404s/times out (VRChat CDN hiccup, revoked link, etc.),
                // swap it for the generated initials avatar instead of showing a broken image.
                window.__avatarFallback = function (img, displayName, sizeClass) {
                    const span = document.createElement('span');
                    span.className = sizeClass;
                    span.style.background = avatarGradient(displayName);
                    span.textContent = getInitials(displayName);
                    img.replaceWith(span);
                };

                // Real photo (via our own avatar proxy, or a direct URL for not-yet-linked
                // VRChat search candidates) when available, otherwise a generated initials avatar.
                function avatarHtml(displayName, imgSrc, sizeClass = 'player-avatar-sm') {
                    const name = displayName || '';
                    if (imgSrc) {
                        return `<img class="${sizeClass}" src="${escHtml(imgSrc)}" alt="" loading="lazy" onerror="window.__avatarFallback(this, '${escHtml(name)}', '${sizeClass}')">`;
                    }
                    return `<span class="${sizeClass}" style="background:${avatarGradient(name)}">${escHtml(getInitials(name))}</span>`;
                }

                // VRChat's CDN rejects direct browser <img> requests (it requires a custom
                // User-Agent naming the calling app, which only our server can send), so
                // linked players' photos are always served through our own cached proxy.
                function proxiedAvatarUrl(username) {
                    return `/api/avatar/${encodeURIComponent(username)}`;
                }

                function relativeTime(dateStr) {
                    if (!dateStr) return '';
                    const t = Date.parse(String(dateStr).replace(' ', 'T'));
                    if (isNaN(t)) return String(dateStr);
                    const diffSec = Math.round((Date.now() - t) / 1000);
                    if (diffSec < 60) return 'Baru saja';
                    const diffMin = Math.round(diffSec / 60);
                    if (diffMin < 60) return `${diffMin} menit lalu`;
                    const diffHour = Math.round(diffMin / 60);
                    if (diffHour < 24) return `${diffHour} jam lalu`;
                    const diffDay = Math.round(diffHour / 24);
                    if (diffDay < 30) return `${diffDay} hari lalu`;
                    const d = new Date(t);
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
                }

                // Resolve a saved analysis reference into a shareable link: a raw hex ID
                // or a legacy URL containing ?analysis=<id> becomes our own analysis-viewer
                // link; anything else (a plain external URL, e.g. chessigma.com) passes
                // through unchanged, since that's a valid analysis_url value in its own right.
                function resolveAnalysisUrl(rawUrl) {
                    if (!rawUrl) return null;
                    const trimmed = String(rawUrl).trim();
                    if (!trimmed) return null;
                    if (/^[a-f0-9]{8,}$/i.test(trimmed)) {
                        return window.location.origin + window.location.pathname + '?analysis=' + trimmed;
                    }
                    const analysisMatch = trimmed.match(/[?&]analysis=([a-f0-9]+)/i);
                    if (analysisMatch) {
                        return window.location.origin + window.location.pathname + '?analysis=' + analysisMatch[1];
                    }
                    return trimmed;
                }

                // Inverse of resolveAnalysisUrl: pulls the raw internal analysis ID out of a
                // match's analysis_url, or null if it's a plain external URL (e.g. chessigma.com)
                // we have no positions data for.
                function extractInternalAnalysisId(rawUrl) {
                    if (!rawUrl) return null;
                    const trimmed = String(rawUrl).trim();
                    if (!trimmed) return null;
                    if (/^[a-f0-9]{8,}$/i.test(trimmed)) return trimmed;
                    const m = trimmed.match(/[?&]analysis=([a-f0-9]+)/i);
                    return m ? m[1] : null;
                }

                // computePlayerAnalysisStats lives inside setupEvents() (near
                // getMoveAnnotationIcon/computeGameAccuracy/classificationLeniency, which it
                // depends on) and is bridged onto window from there — see near
                // window.gotoMoveLine for the same pattern.

                function isMatchValid(m) {
                    return !('is_valid' in m) || m.is_valid === true;
                }

                // Builds this player's match history (newest first) from the already-loaded
                // matchesCache, enriched with opponent/outcome/rating-change from their POV.
                function buildPlayerHistory(playerId) {
                    return matchesCache
                        .filter(m => m.white_id === playerId || m.black_id === playerId)
                        .map(m => {
                            const isWhite = m.white_id === playerId;
                            const opponentId = isWhite ? m.black_id : m.white_id;
                            const opponentName = playerIdMap[opponentId] || null;

                            let outcome = 'draw';
                            if (m.result === '1-0') outcome = isWhite ? 'win' : 'loss';
                            else if (m.result === '0-1') outcome = isWhite ? 'loss' : 'win';

                            const rawChange = isWhite ? m.rating_change_white : m.rating_change_black;
                            const ratingChange = Math.round(parseFloat(rawChange)) || 0;

                            return {
                                id: m.id,
                                date: m.date || '',
                                opponentId,
                                opponentName,
                                color: isWhite ? 'white' : 'black',
                                outcome,
                                ratingChange,
                                isValid: isMatchValid(m),
                                analysisUrl: m.analysis_url || null
                            };
                        })
                        .sort((a, b) => {
                            const ta = Date.parse(String(a.date).replace(' ', 'T'));
                            const tb = Date.parse(String(b.date).replace(' ', 'T'));
                            if (!isNaN(ta) && !isNaN(tb) && ta !== tb) return tb - ta;
                            return (b.id || 0) - (a.id || 0);
                        });
                }

                function renderFormGuide(history) {
                    const recent = history.filter(h => h.isValid).slice(0, 5).reverse();
                    const guideEl = $('#modalFormGuide');
                    const dotsEl = $('#modalFormDots');

                    if (recent.length === 0) {
                        guideEl.style.display = 'none';
                        dotsEl.innerHTML = '';
                        return;
                    }

                    guideEl.style.display = 'flex';
                    dotsEl.innerHTML = recent.map(h => {
                        const letter = h.outcome === 'win' ? 'M' : h.outcome === 'draw' ? 'S' : 'K';
                        const label = h.outcome === 'win' ? 'Menang' : h.outcome === 'draw' ? 'Seri' : 'Kalah';
                        const opp = h.opponentName || `Pemain #${h.opponentId}`;
                        return `<span class="form-dot form-dot--${h.outcome}" title="${escHtml(label)} vs ${escHtml(opp)}">${letter}</span>`;
                    }).join('');
                }

                function renderProfileHistory(history) {
                    $('#modalHistoryCount').textContent = history.length;
                    const container = $('#profileHistoryList');

                    if (history.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.clipboard}</div>
                            <div class="empty-state__text">Belum ada pertandingan tercatat</div>
                        </div>`;
                        return;
                    }

                    container.innerHTML = history.map(h => {
                        const outcomeLetter = h.outcome === 'win' ? 'W' : h.outcome === 'draw' ? 'D' : 'L';
                        const sideIcon = h.color === 'white' ? ICONS.pieceWhite : ICONS.pieceBlack;
                        const changeClass = h.ratingChange > 0 ? 'pos' : h.ratingChange < 0 ? 'neg' : 'zero';
                        const changeLabel = h.ratingChange > 0 ? `+${h.ratingChange}` : `${h.ratingChange}`;
                        const analysisUrl = resolveAnalysisUrl(h.analysisUrl);
                        const opponentHtml = h.opponentName
                            ? `<button class="profile-history-row__opponent" onclick="window.appViewPlayer(${jsStr(h.opponentName)})">${escHtml(h.opponentName)}</button>`
                            : `<span class="profile-history-row__opponent profile-history-row__opponent--deleted">Pemain Dihapus</span>`;

                        return `
                        <div class="profile-history-row ${!h.isValid ? 'is-invalid' : ''}">
                            <span class="profile-history-row__result profile-history-row__result--${h.outcome}">${outcomeLetter}</span>
                            <div class="profile-history-row__main">
                                ${opponentHtml}
                                <div class="profile-history-row__meta">
                                    <span class="profile-history-row__side">${sideIcon}</span>
                                    <span>${escHtml(relativeTime(h.date))}</span>
                                    ${!h.isValid ? `<span class="profile-history-row__invalid-tag">${ICONS.ban} Dianulir</span>` : ''}
                                    ${analysisUrl ? `<a class="profile-history-row__link" href="${escHtml(analysisUrl)}" target="_blank" rel="noopener">${ICONS.chart} Analisis</a>` : ''}
                                </div>
                            </div>
                            <div class="profile-history-row__end">
                                <span class="profile-history-row__rating profile-history-row__rating--${changeClass}">${changeLabel}</span>
                            </div>
                        </div>`;
                    }).join('');
                }

                function switchModalTab(tabName) {
                    $$('.tab-nav--modal .modal-tab-btn').forEach(btn => {
                        btn.classList.toggle('active', btn.dataset.modalTab === tabName);
                    });
                    $$('.modal-tab-content').forEach(panel => {
                        panel.classList.toggle('active', panel.id === 'modalTab' + tabName.charAt(0).toUpperCase() + tabName.slice(1));
                    });
                }

                function renderLeaderboard(filter = '') {
                    const container = $('#leaderboardContainer');
                    let players = playersCache;

                    if (filter) {
                        const q = filter.toLowerCase();
                        players = players.filter(p => p.username.toLowerCase().includes(q));
                    }

                    if (players.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.pawn}</div>
                            <div class="empty-state__text">${filter ? 'Tidak ada pemain yang cocok' : 'Belum ada pemain'}</div>
                        </div>`;
                        return;
                    }

                    let html = `
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Pemain</th>
                                <th>Rating</th>
                                <th class="hide-mobile">W</th>
                                <th class="hide-mobile">D</th>
                                <th class="hide-mobile">L</th>
                                <th>Games</th>
                                <th class="hide-mobile">Win Rate</th>
                                ${isAdminState ? '<th>Aksi Admin</th>' : ''}
                            </tr>
                        </thead>
                        <tbody>`;

                    players.forEach((p, i) => {
                        const rank = i + 1;
                        const rankClass = rank === 1 ? 'gold' : rank === 2 ? 'silver' : rank === 3 ? 'bronze' : 'default';
                        const rankPiece = rank === 1 ? 'king' : rank === 2 ? 'queen' : rank === 3 ? 'rook' : null;
                        const rankPieceHtml = rankPiece ? `<span class="rank-badge__piece rank-badge__piece--${rankPiece}"></span>` : '';
                        const games = parseInt(p.games) || 0;
                        const wins = parseInt(p.wins) || 0;
                        const winRate = games > 0 ? Math.round((wins / games) * 100) : 0;

                        const adminActionsTd = isAdminState ? `
                            <td onclick="event.stopPropagation();">
                                <button class="btn btn--icon btn--xs" onclick="window.appOpenEditPlayer(${jsStr(p.username)}, ${p.rating})" title="Edit Pemain">${ICONS.pencil}</button>
                                <button class="btn btn--danger btn--xs" onclick="window.appDeletePlayer(${jsStr(p.username)})" title="Hapus Pemain">${ICONS.trash}</button>
                            </td>` : '';

                        html += `
                        <tr class="leaderboard-row" data-username="${escHtml(p.username)}" tabindex="0" role="button" title="Lihat profil ${escHtml(p.username)}">
                            <td><span class="rank-badge rank-badge--${rankClass}"><span class="rank-badge__num">${rank}</span>${rankPieceHtml}</span></td>
                            <td>
                                <span class="leaderboard-row__player">
                                    ${avatarHtml(p.vrchat_display_name || p.username, p.avatar_url ? proxiedAvatarUrl(p.username) : null)}
                                    <span class="player-name">${escHtml(p.username)}</span>
                                </span>
                            </td>
                            <td><span class="player-rating">${p.rating}</span></td>
                            <td class="hide-mobile"><span class="stat-value stat-win">${p.wins}</span></td>
                            <td class="hide-mobile"><span class="stat-value stat-draw">${p.draws}</span></td>
                            <td class="hide-mobile"><span class="stat-value stat-loss">${p.losses}</span></td>
                            <td><span class="stat-value">${p.games}</span></td>
                            <td class="hide-mobile">
                                <span class="stat-value">${winRate}%</span>
                                <span class="winrate-bar"><span class="winrate-bar__fill" style="width:${winRate}%"></span></span>
                            </td>
                            ${adminActionsTd}
                        </tr>`;
                    });

                    html += '</tbody></table>';
                    container.innerHTML = html;

                    container.querySelectorAll('.leaderboard-row').forEach(row => {
                        row.addEventListener('click', () => {
                            const username = row.dataset.username;
                            if (username) openPlayerStats(username);
                        });
                        row.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                const username = row.dataset.username;
                                if (username) openPlayerStats(username);
                            }
                        });
                    });
                }

                // Renders a long card list one page at a time, appending the next page when the
                // sentinel below it scrolls into view — the match and analysis histories both grow
                // without bound, and building every card up front costs a visible hitch on open.
                // Safe to call on every re-render (search, filter, admin toggle): the container is
                // cleared and the previous observer disconnected first.
                const PAGE_SIZE = 20;
                const pagedObservers = new WeakMap();

                function renderPaged(container, items, buildHtml) {
                    pagedObservers.get(container)?.disconnect();
                    pagedObservers.delete(container);

                    container.innerHTML = '';
                    const sentinel = document.createElement('div');
                    sentinel.className = 'paged-sentinel';
                    sentinel.textContent = 'Memuat…';
                    container.appendChild(sentinel);

                    let shown = 0;
                    const appendPage = () => {
                        sentinel.insertAdjacentHTML('beforebegin', items.slice(shown, shown + PAGE_SIZE).map(buildHtml).join(''));
                        shown = Math.min(shown + PAGE_SIZE, items.length);
                        if (shown >= items.length) {
                            observer.disconnect();
                            pagedObservers.delete(container);
                            sentinel.remove();
                        }
                    };

                    const observer = new IntersectionObserver((entries) => {
                        // Only act on the sentinel actually coming into view — the callback also
                        // fires on a non-intersecting first observation, and for a list sitting in
                        // a hidden tab, where a rect of all zeros would look like "still on screen"
                        // below and run the whole list out in one go.
                        if (!entries[0].isIntersecting) return;
                        // A page shorter than the viewport leaves the sentinel visible, and the
                        // observer won't fire again without a change in intersection — so keep
                        // appending until it's pushed off screen or the items run out.
                        do {
                            appendPage();
                        } while (shown < items.length && sentinel.getBoundingClientRect().top < window.innerHeight);
                    }, { rootMargin: '400px' });

                    appendPage();
                    if (shown < items.length) {
                        pagedObservers.set(container, observer);
                        observer.observe(sentinel);
                    }
                }

                function renderMatches() {
                    const rawQuery = $('#matchSearch') ? $('#matchSearch').value.toLowerCase().trim() : '';

                    const container = $('#matchesContainer');
                    let matches = [...matchesCache];

                    if (currentMatchFilter === 'valid') {
                        matches = matches.filter(m => !('is_valid' in m) || m.is_valid === true);
                    } else if (currentMatchFilter === 'invalid') {
                        matches = matches.filter(m => 'is_valid' in m && m.is_valid === false);
                    }

                    if (rawQuery) {
                        const tokens = rawQuery.split(/\s+/);

                        tokens.forEach(token => {
                            if (token.startsWith('white:') || token.startsWith('w:')) {
                                const val = token.replace(/^(white|w):/, '');
                                matches = matches.filter(m => (playerIdMap[m.white_id] || '').toLowerCase().includes(val));
                            } else if (token.startsWith('black:') || token.startsWith('b:')) {
                                const val = token.replace(/^(black|b):/, '');
                                matches = matches.filter(m => (playerIdMap[m.black_id] || '').toLowerCase().includes(val));
                            } else if (token.startsWith('y:')) {
                                const val = parseInt(token.replace(/^y:/, ''));
                                matches = matches.filter(m => m.date && parseInt(m.date.substring(0, 4)) === val);
                            } else if (token.startsWith('m:')) {
                                const val = parseInt(token.replace(/^m:/, ''));
                                matches = matches.filter(m => m.date && parseInt(m.date.substring(5, 7)) === val);
                            } else if (token.startsWith('d:')) {
                                const val = parseInt(token.replace(/^d:/, ''));
                                matches = matches.filter(m => m.date && parseInt(m.date.substring(8, 10)) === val);
                            } else {
                                matches = matches.filter(m => {
                                    const wName = (playerIdMap[m.white_id] || '').toLowerCase();
                                    const bName = (playerIdMap[m.black_id] || '').toLowerCase();
                                    return wName.includes(token) || bName.includes(token);
                                });
                            }
                        });
                    }



                    if (matches.length === 0) {
                        container.innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state__icon">${ICONS.clipboard}</div>
                            <div class="empty-state__text">Tidak ada pertandingan dalam kategori ini</div>
                        </div>`;
                        return;
                    }

                    const sorted = matches.reverse();

                    const RESULTS = {
                        '1-0': { text: 'White wins', mod: 'white' },
                        '0-1': { text: 'Black wins', mod: 'black' },
                        '1/2-1/2': { text: 'Draw', mod: 'draw' },
                    };

                    // Name, then the rating the player carried into this game and what the
                    // game did to it — the match-history counterpart of the PGN Elo pill.
                    const playerHtml = (pieceIcon, name, oldRating, change) => {
                        let s = `<span class="history-card__piece">${pieceIcon}</span>`
                            + `<span class="history-card__name">${escHtml(name)}</span>`;
                        if (Number.isFinite(oldRating)) s += `<span class="history-card__rating">${oldRating}</span>`;
                        if (Number.isFinite(change)) {
                            const dir = change > 0 ? 'up' : change < 0 ? 'down' : 'even';
                            const sign = change > 0 ? '+' : change < 0 ? '−' : '±';
                            s += `<span class="history-card__delta history-card__delta--${dir}">${sign}${Math.abs(change)}</span>`;
                        }
                        return s;
                    };

                    // Matches recorded with a PGN point at a saved analysis; its parsed
                    // headers are already in allAnalyses, so no extra request is needed.
                    const analysesById = new Map(allAnalyses.map(an => [an.id, an]));

                    renderPaged(container, sorted, (m) => {
                        const pgnHeaders = analysesById.get(extractInternalAnalysisId(m.analysis_url))?.headers;
                        const whiteName = playerIdMap[m.white_id] || `Pemain #${m.white_id}`;
                        const blackName = playerIdMap[m.black_id] || `Pemain #${m.black_id}`;
                        const isValid = !('is_valid' in m) || m.is_valid === true;
                        const result = RESULTS[m.result] || { text: m.result || '?', mod: 'unknown' };

                        // Resolve analysis URL: build full URL from raw ID or legacy path/absolute URLs
                        const resolvedAnalysisUrl = resolveAnalysisUrl(m.analysis_url);
                        // Defense in depth against a malicious analysis_url (the server now
                        // rejects anything but an internal id or http(s):// URL on write, but
                        // this also guards any record written before that validation existed).
                        // escHtml() only entity-escapes — it doesn't stop a javascript:/data:
                        // URI from sitting in href and firing on click.
                        const isSafeAnalysisUrl = resolvedAnalysisUrl && /^https?:\/\//i.test(resolvedAnalysisUrl);

                        const analysisLink = isSafeAnalysisUrl
                            ? `<a class="history-card__link" href="${escHtml(resolvedAnalysisUrl)}" target="_blank" rel="noopener">${ICONS.chart} Open Analysis →</a>`
                            : '';

                        const statusChip = !isValid
                            ? `<span class="history-card__chip history-card__chip--danger">${ICONS.ban} Anulir (Invalid)</span>`
                            : '';

                        let adminButtons = '';
                        if (isAdminState) {
                            if (isValid) {
                                adminButtons += `<button class="btn btn--warning btn--xs match-actions-btn" onclick="window.appInvalidateMatch(${m.id})" title="Anulir Pertandingan">${ICONS.ban} Anulir</button>`;
                            } else {
                                adminButtons += `<button class="btn btn--success btn--xs match-actions-btn" onclick="window.appRevalidateMatch(${m.id})" title="Pulihkan Pertandingan">${ICONS.refresh} Pulihkan</button>`;
                            }
                            adminButtons += `<button class="btn btn--icon btn--xs match-actions-btn" onclick="window.appOpenEditMatch(${m.id}, ${jsStr(m.result)}, ${jsStr(m.analysis_url || '')})" title="Edit Match">${ICONS.pencil} Edit</button>`;
                            adminButtons += `<button class="btn btn--danger btn--xs match-actions-btn" onclick="window.appDeleteMatch(${m.id})" title="Hapus Match">${ICONS.trash} Hapus</button>`;
                        }

                        return `
                        <div class="history-card${!isValid ? ' history-card--invalid' : ''}">
                            <div class="history-card__header">
                                <span>${ICONS.clock} ${escHtml(m.date || '—')}</span>
                                ${analysisLink}
                            </div>
                            <div class="history-card__body">
                                <div class="history-card__players">
                                    ${playerHtml(ICONS.pieceWhite, whiteName, m.old_white_rating, m.rating_change_white)}
                                    <span class="history-card__vs">vs</span>
                                    ${playerHtml(ICONS.pieceBlack, blackName, m.old_black_rating, m.rating_change_black)}
                                    <span class="history-card__result history-card__result--${result.mod}">${escHtml(result.text)}</span>
                                </div>
                                <div class="history-card__chips">
                                    <span class="history-card__chip">#${m.id}</span>
                                    ${statusChip}
                                    ${pgnChipsHtml(pgnHeaders)}
                                </div>
                            </div>
                            ${isAdminState ? `<div class="history-card__footer">${adminButtons}</div>` : ''}
                        </div>`;
                    });
                }

                // ==========================================
                // ANALYSES HISTORY LIST
                // ==========================================

                let allAnalyses = [];

                // ── PGN header helpers — shared by the analysis and match history cards ──

                // Case-insensitive tag lookup that treats the '-' and '?' placeholders as missing.
                function pgnTag(headers, key) {
                    const k = Object.keys(headers).find(x => x.toLowerCase() === key.toLowerCase());
                    const v = k ? headers[k] : null;
                    return (v && v !== '-' && v !== '?') ? v : null;
                }

                // ── TimeControl formatter ─────────────────────────────────────────
                // PGN standard: values in seconds (e.g. 900+10 = 15 min + 10s).
                // DGT/tournament style: base < 60 treated as minutes (e.g. 15+5 = 15 min + 5s).
                // Handles: "3600" → "60 min", "900+10" → "15 min + 10s", "15+5" → "15 min + 5s"
                function formatTimeControl(tc) {
                    if (!tc || tc === '-' || tc === '?') return null;
                    tc = tc.trim();

                    // Pure number — always seconds per PGN standard
                    if (/^\d+$/.test(tc)) {
                        const secs = parseInt(tc, 10);
                        if (secs === 0) return null;
                        if (secs < 60) return `${secs}s`;
                        const mins = secs / 60;
                        return Number.isInteger(mins) ? `${mins} min` : `${(secs / 60).toFixed(1)} min`;
                    }

                    // base+increment: "900+10", "15+5", "15 + 5"
                    const m = tc.match(/^(\d+)\s*\+\s*(\d+)$/);
                    if (m) {
                        const base = parseInt(m[1], 10);
                        const inc = parseInt(m[2], 10);
                        // If base < 60: DGT/tournament format — treat as minutes directly
                        // If base >= 60: PGN seconds format — convert to minutes
                        let baseFmt;
                        if (base < 60) {
                            baseFmt = `${base} min`;
                        } else {
                            const mins = base / 60;
                            baseFmt = Number.isInteger(mins) ? `${mins} min` : `${(base / 60).toFixed(1)} min`;
                        }
                        return inc > 0 ? `${baseFmt} + ${inc}s` : baseFmt;
                    }

                    return tc; // unknown format — return as-is
                }

                // The PGN badges: event, variant, time control, date, round, site, opening.
                // '' when there are no headers or none of these tags are set.
                function pgnChipsHtml(headers) {
                    if (!headers) return '';
                    const tag = (key) => pgnTag(headers, key);
                    const chip = (text, icon = '', mod = '') => text
                        ? `<span class="history-card__chip${mod ? ` history-card__chip--${mod}` : ''}">${icon ? icon + ' ' : ''}${escHtml(text)}</span>`
                        : '';

                    // Prefer the PGN's own [Opening] tag; when a game only carries
                    // [ECO "B01"], resolve it to a real name ("Scandinavian Defense")
                    // via ecoNameIndex rather than showing the bare code.
                    const ecoTag = tag('ECO');
                    const opening = tag('Opening') || (ecoTag && ecoNameIndex[ecoTag]) || ecoTag;
                    const round = tag('Round');

                    return chip(tag('Event'), ICONS.trophy)
                        + chip(tag('Variant'), ICONS.pawn)
                        + chip(formatTimeControl(tag('TimeControl')), ICONS.stopwatch)
                        + chip(tag('Date'), ICONS.calendar)
                        + chip(round && 'R' + round)
                        + chip(tag('Site'), ICONS.pin)
                        + chip(opening, ICONS.book, 'accent');
                }

                async function fetchAnalyses(silent = false) {
                    try {
                        const res = await apiCall('/api/analyses');
                        if (res.success) {
                            allAnalyses = res.analyses || [];
                            if ($('#analysisCount')) {
                                $('#analysisCount').textContent = allAnalyses.length;
                            }
                            renderReviewQueue();
                            renderAnalyses();
                            // Match cards show PGN badges from these headers, and on page load
                            // the match list renders before this data arrives.
                            renderMatches();
                        }
                    } catch (err) {
                        console.error('Gagal memuat analisis', err);
                    }
                }

                // ── Admin "Analisis Game Review" panel ──
                // Every saved game with how deeply it was reviewed (lowest / average search
                // depth over its positions), so unreviewed or shallow games — which leave
                // profile accuracy and Brilliant counts thin — can be picked and (re)analyzed.
                const reviewSelection = new Set();

                // ── Batch run state ──
                // A batch reviews one game at a time, so the panel is the only place that can
                // show where the run as a whole is: per-game progress keyed by analysis id —
                // kept outside the table so re-rendering the queue (every finished game calls
                // fetchAnalyses) doesn't lose it — plus the gate every engine worker parks on
                // between positions while the run is paused.
                const reviewProgress = new Map(); // id -> { state, done, total, phase }
                let reviewBatchIds = [];          // the ids of the run in progress, in order
                let reviewActiveId = null;        // the game being reviewed right now
                let reviewPaused = false;
                let reviewStopRequested = false;
                let reviewPauseWaiters = [];
                let reanalysisRunning = false;

                // Resolves once the run is no longer paused, or null when it isn't paused at
                // all. Workers loop on it — `for (let gate; (gate = whenReviewResumed());) await
                // gate;` — so a pause taken while one was already waiting still holds them.
                function whenReviewResumed() {
                    if (!reviewPaused) return null;
                    return new Promise(resolve => reviewPauseWaiters.push(resolve));
                }

                function setReviewPaused(paused) {
                    reviewPaused = paused;
                    if (!paused) reviewPauseWaiters.splice(0).forEach(resolve => resolve());
                    renderReviewQueue();
                    renderReviewBatchBar();
                }

                function setReviewButtonsEnabled(enabled) {
                    for (const sel of ['#btnAnalyzeUnanalyzed', '#btnAnalyzeShallow', '#btnAnalyzeSelected']) {
                        const btn = $(sel);
                        if (btn) btn.disabled = !enabled;
                    }
                }

                const REVIEW_STATE_BADGES = {
                    queued: ['Menunggu', 'orange'],
                    done: ['Selesai', 'green'],
                    failed: ['Gagal', 'red'],
                    skipped: ['Dibatalkan', 'orange'],
                };

                // How far through a game a review is, 0..1. The second-line pass restarts the
                // position counter on a smaller set of positions, so it counts as the game's last
                // tenth instead of dropping the bar back to zero — a bar that only ever moves
                // forward. A game whose pass finds nothing to re-search just jumps 0.9 → done.
                function reviewFraction(prog) {
                    if (!prog?.total) return 0;
                    const frac = Math.min(1, prog.done / prog.total);
                    return prog.phase === 'posisi' ? frac * 0.9 : 0.9 + frac * 0.1;
                }

                // One game's progress, as it appears under that game's row.
                function reviewProgressHtml(id) {
                    const prog = reviewProgress.get(id);
                    if (!prog) return '';
                    if (prog.state !== 'running') {
                        const [text, color] = REVIEW_STATE_BADGES[prog.state] || ['', ''];
                        return text ? `<div style="margin-top:6px;"><span class="card__badge card__badge--${color}">${text}</span></div>` : '';
                    }
                    const pct = Math.round(reviewFraction(prog) * 100);
                    const label = reviewPaused ? 'Dijeda' : `${prog.phase} ${prog.done}/${prog.total || '?'}`;
                    return `
                    <div style="margin-top:6px;">
                        <div style="display:flex; justify-content:space-between; gap:8px; font-size:0.7rem; color:var(--text-secondary); margin-bottom:3px;">
                            <span>${escHtml(label)}</span><span>${pct}%</span>
                        </div>
                        <div style="background:rgba(255,255,255,0.08); border-radius:6px; height:6px; overflow:hidden;">
                            <div style="width:${pct}%; height:100%; background:var(--accent-primary); border-radius:6px; transition:width 0.2s ease;"></div>
                        </div>
                    </div>`;
                }

                // Repaints one game's progress block in place. A game fires this once per
                // analyzed position, and re-rendering the whole table that often would drop the
                // container's scroll position (and fight the checkboxes) on every tick.
                function paintReviewRow(id) {
                    for (const el of $$('#reviewQueue [data-review-progress]')) {
                        if (el.getAttribute('data-review-progress') === id) el.innerHTML = reviewProgressHtml(id);
                    }
                }

                // Called by the running Game Review for every position it finishes.
                function updateReviewRowProgress(id, done, total, phase) {
                    const prog = reviewProgress.get(id);
                    if (!prog) return;
                    Object.assign(prog, { state: 'running', done, total, phase });
                    paintReviewRow(id);
                    renderReviewBatchBar();
                }

                // The run's own bar: finished games plus how far into the current one it is, so
                // it advances smoothly instead of jumping once per game.
                function renderReviewBatchBar() {
                    const panel = $('#reviewBatchPanel');
                    if (!panel) return;
                    panel.style.display = reanalysisRunning ? 'block' : 'none';
                    if (!reanalysisRunning) return;

                    const total = reviewBatchIds.length;
                    const finished = reviewBatchIds.filter(id => ['done', 'failed', 'skipped'].includes(reviewProgress.get(id)?.state)).length;
                    const active = reviewActiveId ? reviewProgress.get(reviewActiveId) : null;
                    const pct = total ? Math.min(100, Math.round(((finished + reviewFraction(active)) / total) * 100)) : 0;

                    $('#reviewBatchIndex').textContent = Math.min(total, finished + (active ? 1 : 0));
                    $('#reviewBatchTotal').textContent = total;
                    $('#reviewBatchPct').textContent = pct + '%';
                    $('#reviewBatchBar').style.width = pct + '%';
                    $('#reviewBatchState').textContent = reviewStopRequested ? 'Menghentikan…' : reviewPaused ? 'Dijeda' : 'Berjalan';
                    const pauseBtn = $('#btnReviewPause');
                    if (pauseBtn) {
                        pauseBtn.innerHTML = reviewPaused ? `${ICONS.play} Lanjutkan` : `${ICONS.pause} Jeda`;
                        pauseBtn.disabled = reviewStopRequested;
                    }
                    const stopBtn = $('#btnReviewStop');
                    if (stopBtn) stopBtn.disabled = reviewStopRequested;
                }

                // Games reviewed less deeply than `depth` everywhere: the lowest depth any
                // position reached is below it. Null depth = no target set, so none qualify.
                function isShallowReview(an, depth) {
                    return !!depth && an.analyzed !== false && (!an.depth || an.depth.min < depth);
                }

                function reviewTargetDepth() {
                    const depth = Number($('#reanalysisDepth')?.value);
                    return Number.isInteger(depth) && depth >= 8 && depth <= 26 ? depth : null;
                }

                function renderReviewQueue() {
                    const container = $('#reviewQueue');
                    if (!container) return;
                    const target = reviewTargetDepth();
                    const filter = $('#reviewQueueFilter')?.value || 'all';

                    for (const id of reviewSelection) {
                        if (!allAnalyses.some(an => an.id === id)) reviewSelection.delete(id);
                    }
                    $('#unanalyzedCount').textContent = allAnalyses.filter(an => an.analyzed === false).length;
                    $('#shallowCount').textContent = allAnalyses.filter(an => isShallowReview(an, target)).length;
                    $('#selectedReviewCount').textContent = reviewSelection.size;

                    // Work-queue order: unreviewed first, then shallowest, newest within each.
                    const rank = an => an.analyzed === false ? -1 : (an.depth ? an.depth.min : 0);
                    const rows = allAnalyses
                        .filter(an => filter === 'all'
                            || (filter === 'unanalyzed' && an.analyzed === false)
                            || (filter === 'analyzed' && an.analyzed !== false)
                            || (filter === 'shallow' && isShallowReview(an, target)))
                        .sort((a, b) => rank(a) - rank(b) || String(b.created_at).localeCompare(String(a.created_at)));

                    renderReviewBatchBar();

                    if (rows.length === 0) {
                        const text = filter === 'shallow' && !target
                            ? 'Isi depth di atas untuk mencari game yang lebih dangkal.'
                            : 'Tidak ada game di daftar ini.';
                        container.innerHTML = `<div class="empty-state"><div class="empty-state__text">${text}</div></div>`;
                        return;
                    }

                    const allChecked = rows.every(an => reviewSelection.has(an.id));
                    container.innerHTML = `
                    <table class="leaderboard-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" data-review-all ${allChecked ? 'checked' : ''} ${reanalysisRunning ? 'disabled' : ''} aria-label="Pilih semua di daftar ini"></th>
                                <th>Game</th>
                                <th>Depth (min / rata-rata)</th>
                                <th>Dianalisis</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>${rows.map(an => {
                            const h = an.headers || {};
                            const players = `${escHtml(pgnTag(h, 'White') || '?')} vs ${escHtml(pgnTag(h, 'Black') || '?')}`;
                            const meta = [pgnTag(h, 'Date'), pgnTag(h, 'Result')].filter(Boolean).map(escHtml).join(' · ');
                            const depth = an.analyzed === false
                                ? '<span class="card__badge card__badge--red">Belum dianalisis</span>'
                                : !an.depth
                                    ? '<span class="card__badge card__badge--orange">Tidak tercatat</span>'
                                    : `<span class="card__badge card__badge--${isShallowReview(an, target) ? 'orange' : 'green'}">${an.depth.min} / ${an.depth.avg}</span>`;
                            return `
                            <tr${an.id === reviewActiveId ? ' style="background:rgba(127,166,80,0.10);"' : ''}>
                                <td><input type="checkbox" data-review-id="${escHtml(an.id)}" ${reviewSelection.has(an.id) ? 'checked' : ''} ${reanalysisRunning ? 'disabled' : ''} aria-label="Pilih game"></td>
                                <td><strong>${players}</strong><div class="stat-value" style="font-size:0.75rem;">${meta || escHtml(an.id)}</div>
                                    <div data-review-progress="${escHtml(an.id)}">${reviewProgressHtml(an.id)}</div></td>
                                <td>${depth}</td>
                                <td><span class="stat-value">${escHtml(an.analyzed === false ? '—' : (an.updated_at || an.created_at))}</span></td>
                                <td><button class="btn btn--secondary btn--xs" ${reanalysisRunning ? 'disabled' : ''} onclick="window.appReanalyze(${jsStr(an.id)})">${ICONS.refresh} ${an.analyzed === false ? 'Analisis' : 'Ulang'}</button></td>
                            </tr>`;
                        }).join('')}</tbody>
                    </table>`;
                    container.dataset.visibleIds = JSON.stringify(rows.map(an => an.id));
                }

                function renderAnalyses() {
                    const container = $('#analysesContainer');
                    if (!container) return;

                    const rawQuery = $('#analysisSearch') ? $('#analysisSearch').value.toLowerCase().trim() : '';
                    let analyses = [...allAnalyses];

                    if (rawQuery) {
                        const tokens = rawQuery.split(/\s+/);
                        tokens.forEach(token => {
                            if (token.startsWith('white:') || token.startsWith('w:')) {
                                const val = token.replace(/^(white|w):/, '');
                                analyses = analyses.filter(an => {
                                    if (!an.headers) return false;
                                    const wKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'white');
                                    const white = wKey ? an.headers[wKey] : '';
                                    return white.toLowerCase().includes(val);
                                });
                            } else if (token.startsWith('black:') || token.startsWith('b:')) {
                                const val = token.replace(/^(black|b):/, '');
                                analyses = analyses.filter(an => {
                                    if (!an.headers) return false;
                                    const bKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'black');
                                    const black = bKey ? an.headers[bKey] : '';
                                    return black.toLowerCase().includes(val);
                                });
                            } else if (token.startsWith('elo:') || token.startsWith('rating:')) {
                                const val = token.replace(/^(elo|rating):/, '');
                                analyses = analyses.filter(an => {
                                    if (!an.headers) return false;
                                    const wEloKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'whiteelo');
                                    const bEloKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'blackelo');
                                    const wElo = wEloKey ? an.headers[wEloKey] : '';
                                    const bElo = bEloKey ? an.headers[bEloKey] : '';
                                    return wElo.toLowerCase().includes(val) || bElo.toLowerCase().includes(val);
                                });
                            } else if (token.startsWith('title:')) {
                                const val = token.replace(/^title:/, '');
                                analyses = analyses.filter(an => {
                                    if (!an.headers) return false;
                                    const wTitleKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'whitetitle');
                                    const bTitleKey = Object.keys(an.headers).find(k => k.toLowerCase() === 'blacktitle');
                                    const wTitle = wTitleKey ? an.headers[wTitleKey] : '';
                                    const bTitle = bTitleKey ? an.headers[bTitleKey] : '';
                                    return wTitle.toLowerCase().includes(val) || bTitle.toLowerCase().includes(val);
                                });
                            } else {
                                analyses = analyses.filter(an => {
                                    if (!an.headers) return false;
                                    return Object.entries(an.headers).some(([k, v]) =>
                                        k.toLowerCase().includes(token) || String(v).toLowerCase().includes(token)
                                    );
                                });
                            }
                        });
                    }

                    if (analyses.length === 0) {
                        if (allAnalyses.length === 0) {
                            container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state__icon">${ICONS.book}</div>
                                <div class="empty-state__text">Belum ada analisis tersimpan</div>
                            </div>`;
                        } else {
                            container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state__icon">${ICONS.search}</div>
                                <div class="empty-state__text">Tidak ada analisis yang cocok dengan pencarian</div>
                            </div>`;
                        }
                        return;
                    }

                    // ── Result helpers ──────────────────────────────────────────────
                    function resultLabel(r) {
                        if (r === '1-0') return { text: 'White wins', color: 'rgba(220,220,220,0.18)', border: 'rgba(220,220,220,0.35)' };
                        if (r === '0-1') return { text: 'Black wins', color: 'rgba(40,40,60,0.5)', border: 'rgba(120,120,180,0.3)' };
                        if (r === '1/2-1/2') return { text: 'Draw', color: 'rgba(210,175,50,0.15)', border: 'rgba(210,175,50,0.35)' };
                        return { text: r || '?', color: 'rgba(255,255,255,0.06)', border: 'rgba(255,255,255,0.15)' };
                    }

                    // ── Card builder ────────────────────────────────────────────────
                    renderPaged(container, analyses, (an) => {
                        const shareUrl = window.location.origin + window.location.pathname + '?analysis=' + an.id;

                        // Admin delete button — always re-evaluated so it tracks login/logout instantly
                        const adminBtn = isAdminState
                            ? `<button class="btn btn--secondary btn--xs" onclick="window.appReanalyze(${jsStr(an.id)})" title="Jalankan Game Review lagi, dengan depth pilihan">${ICONS.refresh} ${an.analyzed === false ? 'Analisis' : 'Analisis Ulang'}</button>`
                              + `<button class="btn btn--danger btn--xs" onclick="window.appDeleteAnalysis(${jsStr(an.id)})" title="Hapus Analisis">${ICONS.trash} Hapus</button>`
                            : '';

                        // ── Parse PGN headers ──
                        let cardBody = '';
                        if (an.headers && Object.keys(an.headers).length > 0) {
                            const h = an.headers;

                            const getTag = (key) => pgnTag(h, key);

                            // Players
                            const whiteName = getTag('White') || '?';
                            const wTitle = getTag('WhiteTitle');
                            const wElo = getTag('WhiteElo') || getTag('WhiteRating');

                            const blackName = getTag('Black') || '?';
                            const bTitle = getTag('BlackTitle');
                            const bElo = getTag('BlackElo') || getTag('BlackRating');

                            // Build player HTML: [TITLE] Name [Elo]
                            const playerHtml = (name, title, elo) => {
                                let s = '';
                                if (title) s += `<span style="color:#d4af37;font-weight:900;font-size:0.75rem;letter-spacing:.5px;margin-right:4px;vertical-align:middle;">${escHtml(title)}</span>`;
                                s += `<span style="font-weight:600;">${escHtml(name)}</span>`;
                                if (elo) s += ` <span style="display:inline-block;background:rgba(255,255,255,0.09);border:1px solid rgba(255,255,255,0.15);padding:0 5px;border-radius:3px;font-size:0.7rem;color:#bbb;vertical-align:middle;margin-left:3px;">${escHtml(elo)}</span>`;
                                return s;
                            };

                            const result = getTag('Result') || '?';
                            const rl = resultLabel(result);

                            // Tags that are explicitly rendered above — everything else goes to "extra"
                            const rendered = new Set([
                                'white', 'black', 'result', 'opening', 'eco',
                                'whiteelo', 'blackelo', 'whiterating', 'blackrating',
                                'whitetitle', 'blacktitle',
                                'event', 'site', 'variant', 'timecontrol', 'date', 'round',
                                // Always hidden
                                'fen', 'setup', 'epgn',
                                'whitefideid', 'blackfideid',
                                'gameid', 'game id',
                            ]);
                            const extras = Object.entries(h)
                                .filter(([k, v]) => !rendered.has(k.toLowerCase()) && v && v !== '?' && v !== '-')
                                .map(([k, v]) => `<span><b>${escHtml(k)}:</b> ${escHtml(v)}</span>`);

                            // ── Card body HTML ──
                            cardBody = `
                            <div style="display:flex;flex-direction:column;gap:8px;">

                                <!-- Players row -->
                                <div style="display:flex;align-items:center;flex-wrap:wrap;gap:6px;font-size:0.88rem;">
                                    <span style="opacity:.6;">${ICONS.pieceWhite}</span>
                                    ${playerHtml(whiteName, wTitle, wElo)}
                                    <span style="color:var(--text-secondary);font-size:0.75rem;font-style:italic;margin:0 2px;">vs</span>
                                    <span style="opacity:.6;">${ICONS.pieceBlack}</span>
                                    ${playerHtml(blackName, bTitle, bElo)}
                                    <span style="margin-left:auto;padding:2px 9px;background:${rl.color};border:1px solid ${rl.border};border-radius:4px;font-size:0.72rem;font-weight:700;letter-spacing:.5px;white-space:nowrap;">${escHtml(rl.text)}</span>
                                </div>

                                <!-- Meta chips row -->
                                <div class="history-card__chips">${pgnChipsHtml(h)}</div>

                                ${extras.length > 0 ? `<div style="font-size:0.7rem;color:var(--text-secondary);display:flex;flex-wrap:wrap;gap:6px;opacity:.75;">${extras.join('')}</div>` : ''}
                            </div>`;
                        } else {
                            cardBody = `<div style="font-size:0.82rem;color:var(--text-secondary);font-style:italic;padding:4px 0;">Informasi PGN tidak tersedia — Free Mode</div>`;
                        }

                        return `
                        <div style="display:flex;flex-direction:column;gap:0;border:1px solid rgba(255,255,255,0.08);border-radius:8px;overflow:hidden;background:rgba(0,0,0,0.18);">
                            <!-- Card header: date + link -->
                            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;background:rgba(255,255,255,0.03);border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.75rem;color:var(--text-secondary);">
                                <span>${ICONS.clock} ${escHtml(an.created_at || '—')}</span>
                                <a href="${escHtml(shareUrl)}" target="_blank" rel="noopener" style="color:var(--accent-primary);text-decoration:none;font-weight:600;font-size:0.75rem;">${ICONS.chart} Open Analysis →</a>
                            </div>
                            <!-- Card body: PGN info -->
                            <div style="padding:12px 14px;">
                                ${cardBody}
                            </div>
                            <!-- Card footer: actions -->
                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;padding:8px 14px;background:rgba(255,255,255,0.02);border-top:1px solid rgba(255,255,255,0.05);">
                                <button class="btn btn--secondary btn--xs" onclick="navigator.clipboard.writeText(${jsStr(shareUrl)}).then(()=>toast('Link copied!','success'))">${ICONS.copy} Copy Link</button>
                                ${adminBtn}
                            </div>
                        </div>`;
                    });
                }

                window.appDeleteAnalysis = async (id) => {
                    if (!confirm(`Hapus analisis ${id} secara permanen? Link yang sudah dibagikan akan mati.`)) return;
                    try {
                        const res = await apiCall(`/api/analyses/${encodeURIComponent(id)}`, { method: 'DELETE' });
                        if (res.success) {
                            toast(res.message || 'Analisis dihapus', 'success');
                            fetchAnalyses(true);
                        }
                    } catch (err) {
                        toast(err.message || 'Gagal menghapus', 'error');
                    }
                };

                function renderPlayerProfile(player, history) {
                    const games = parseInt(player.games) || 0;
                    const wins = parseInt(player.wins) || 0;
                    const draws = parseInt(player.draws) || 0;
                    const losses = parseInt(player.losses) || 0;
                    const winRate = games > 0 ? Math.round((wins / games) * 100) : 0;
                    const total = Math.max(wins + draws + losses, 1);

                    $('#statGames').textContent = games;
                    $('#statWinRate').textContent = `${winRate}%`;

                    $('#legendWins').textContent = wins;
                    $('#legendDraws').textContent = draws;
                    $('#legendLosses').textContent = losses;

                    $('#wdlSegWin').style.width = `${(wins / total) * 100}%`;
                    $('#wdlSegDraw').style.width = `${(draws / total) * 100}%`;
                    $('#wdlSegLoss').style.width = `${(losses / total) * 100}%`;

                    const validCount = history.filter(h => h.isValid).length;
                    const invalidCount = history.length - validCount;
                    $('#statValidMatches').textContent = `${validCount} valid`;
                    $('#statInvalidMatches').textContent = `${invalidCount} dianulir`;

                    renderFormGuide(history);
                    renderProfileHistory(history);
                }

                function openPlayerStats(username) {
                    const player = playersCache.find(p => p.username === username);
                    if (!player) {
                        toast('Data pemain tidak ditemukan.', 'error');
                        return;
                    }

                    activePlayerUsername = username;

                    $('#modalPlayerUsername').textContent = username;
                    if (player.avatar_url) {
                        $('#modalPlayerAvatar').style.background = 'none';
                        $('#modalPlayerAvatar').innerHTML = avatarHtml(player.vrchat_display_name || username, proxiedAvatarUrl(username), 'modal-avatar-img');
                    } else {
                        $('#modalPlayerAvatar').style.background = avatarGradient(username);
                        $('#modalPlayerAvatar').textContent = getInitials(username);
                    }
                    $('#modalPlayerRatingBadge').textContent = `Rating: ${player.rating}`;

                    const rankIndex = playersCache.findIndex(p => p.username === username);
                    const rankLabel = rankIndex >= 0 ? `#${rankIndex + 1} dari ${playersCache.length}` : '—';
                    $('#modalPlayerRankBadge').textContent = rankLabel;
                    $('#statRank').textContent = rankIndex >= 0 ? `#${rankIndex + 1}` : '—';

                    switchModalTab('overview');
                    $('#playerStatsModal').style.display = 'flex';

                    const history = buildPlayerHistory(player.id);
                    renderPlayerProfile(player, history);
                    refreshPlayerAnalysisStats(username, history);

                    // Guard against the rare case a profile is opened before the initial
                    // matches fetch (kicked off in checkConnection) has resolved yet.
                    if (matchesCache.length === 0 && (parseInt(player.games) || 0) > 0) {
                        fetchMatches(true).then(() => {
                            if (activePlayerUsername !== username) return;
                            const freshHistory = buildPlayerHistory(player.id);
                            renderPlayerProfile(player, freshHistory);
                            refreshPlayerAnalysisStats(username, freshHistory);
                        });
                    }
                }

                // Populates the Akurasi / Brilliant stat boxes — async since it fetches each
                // analyzed game's stored positions. Guarded against the profile having been
                // closed or switched to a different player while this was in flight.
                function refreshPlayerAnalysisStats(username, history) {
                    $('#statAvgAccuracy').textContent = '…';
                    $('#statBrilliantCount').textContent = '…';

                    window.computePlayerAnalysisStats(username, history).then(stats => {
                        if (activePlayerUsername !== username) return;
                        if (stats.gamesAnalyzed === 0) {
                            $('#statAvgAccuracy').textContent = '—';
                            $('#statBrilliantCount').textContent = '—';
                            const note = 'Belum ada game dengan PGN yang sudah dianalisis Stockfish.';
                            $('#statAccuracyBox').title = note;
                            $('#statBrilliantBox').title = note;
                        } else {
                            $('#statAvgAccuracy').textContent = `${stats.avgAccuracy}%`;
                            $('#statBrilliantCount').textContent = stats.brilliants;
                            const note = `Dari ${stats.gamesAnalyzed} game yang sudah dianalisis.`;
                            $('#statAccuracyBox').title = `Rata-rata akurasi Stockfish. ${note}`;
                            $('#statBrilliantBox').title = `Total langkah Brilliant. ${note}`;
                        }
                    }).catch(() => {
                        if (activePlayerUsername !== username) return;
                        $('#statAvgAccuracy').textContent = '—';
                        $('#statBrilliantCount').textContent = '—';
                    });
                }

                // ── Global Handlers for Admins ──
                window.appDeleteAdmin = async function (username) {
                    if (!confirm(`Hapus admin user '${username}'?`)) return;
                    try {
                        const res = await apiCall(`/api/admin/admins/${encodeURIComponent(username)}`, { method: 'DELETE' });
                        if (res.success) {
                            toast(`Admin '${username}' berhasil dihapus.`, 'success');
                            await fetchAdmins(true);
                        } else {
                            toast(res.error || 'Gagal menghapus admin', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appOpenEditAdmin = function (username) {
                    $('#editAdminUsernameHidden').value = username;
                    $('#editAdminUsernameDisplay').value = username;
                    $('#editAdminPassword').value = '';
                    $('#editAdminModal').style.display = 'flex';
                };

                // ── Global Handlers for Tokens ──
                window.appOpenEditToken = function (tokenId, name, isActive) {
                    $('#editTokenId').value = tokenId;
                    $('#editTokenName').value = name;
                    $('#editTokenActive').value = isActive ? '1' : '0';
                    $('#editTokenModal').style.display = 'flex';
                };

                window.appRevokeToken = async function (tokenId) {
                    if (!confirm('Cabut dan hapus API Token ini? Aplikasi/bot yang menggunakannya akan kehilangan akses.')) return;
                    try {
                        const res = await apiCall(`/api/admin/tokens/${encodeURIComponent(tokenId)}`, { method: 'DELETE' });
                        if (res.success) {
                            toast('API Token berhasil dicabut.', 'success');
                            await fetchTokens(true);
                        } else {
                            toast(res.error || 'Gagal mencabut token', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                // ── Global Handlers for VRChat Gallery ──
                window.appHideGalleryPhoto = async function (imageId) {
                    try {
                        const res = await apiCall('/api/admin/gallery/hide', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ image_id: imageId }),
                        });
                        if (res.success) {
                            toast('Foto disembunyikan dari galeri publik.', 'success');
                            await fetchGalleryPhotos(true);
                        } else {
                            toast(res.error || 'Gagal menyembunyikan foto', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appUnhideGalleryPhoto = async function (imageId) {
                    try {
                        const res = await apiCall('/api/admin/gallery/unhide', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ image_id: imageId }),
                        });
                        if (res.success) {
                            toast('Foto ditampilkan kembali di galeri publik.', 'success');
                            await fetchGalleryPhotos(true);
                        } else {
                            toast(res.error || 'Gagal menampilkan foto', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appTriggerGalleryUpload = function (galleryId) {
                    pendingUploadGalleryId = galleryId;
                    $('#galleryUploadInput').click();
                };

                // ── Global Handlers for Newsletter (VRChat Group Posts) ──
                window.appOpenEditPost = function (postId) {
                    const post = newsletterPostsCache.find(p => p.id === postId);
                    if (!post) return;
                    $('#editPostId').value = post.id;
                    $('#editPostTitle').value = post.title;
                    $('#editPostText').value = post.text;
                    $('#editPostVisibility').value = post.visibility;
                    $('#editPostNotify').checked = false;
                    $('#editPostModal').style.display = 'flex';
                };

                window.appDeletePost = async function (postId) {
                    if (!confirm('Hapus post ini dari grup VRChat? Tindakan ini tidak bisa dibatalkan.')) return;
                    try {
                        const res = await apiCall(`/api/admin/newsletter/posts/${encodeURIComponent(postId)}`, { method: 'DELETE' });
                        if (res.success) {
                            toast('Post berhasil dihapus.', 'success');
                            await fetchNewsletterPosts(true);
                        } else {
                            toast(res.error || 'Gagal menghapus post', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appTriggerPostImageUpload = function (postId) {
                    pendingPostImageId = postId;
                    $('#postImageInput').click();
                };

                // ── Global Handlers for Players/Matches ──
                window.appViewPlayer = function (username) {
                    openPlayerStats(username);
                };

                window.appOpenEditPlayer = function (username, rating) {
                    $('#editPlayerOldUsername').value = username;
                    $('#editPlayerUsername').value = username;
                    $('#editPlayerRating').value = rating;

                    const player = playersCache.find(p => p.username === username) || null;
                    renderVrchatLinkStatus(player);
                    $('#vrchatSearchInput').value = username;
                    $('#vrchatSearchResults').innerHTML = '';

                    $('#editPlayerModal').style.display = 'flex';
                };

                // ── VRChat Profile Linking (Admin) ──
                function renderVrchatLinkStatus(player) {
                    const box = $('#vrchatLinkStatus');
                    if (!player || !player.vrchat_user_id) {
                        box.innerHTML = `<span class="vrchat-link-status__empty">Belum ditautkan ke akun VRChat</span>`;
                        return;
                    }

                    box.innerHTML = `
                    <div class="vrchat-link-status__linked">
                        ${avatarHtml(player.vrchat_display_name || player.username, proxiedAvatarUrl(player.username))}
                        <span>Tertaut sebagai <strong>${escHtml(player.vrchat_display_name || player.vrchat_user_id)}</strong></span>
                        <button type="button" class="btn btn--danger btn--xs" id="btnVrchatUnlink">Putuskan</button>
                    </div>`;

                    $('#btnVrchatUnlink').addEventListener('click', () => unlinkVrchat(player.username));
                }

                async function searchVrchat() {
                    const query = $('#vrchatSearchInput').value.trim();
                    const resultsEl = $('#vrchatSearchResults');
                    if (!query) {
                        toast('Ketik nama VRChat untuk dicari', 'error');
                        return;
                    }

                    resultsEl.innerHTML = `<div class="vrchat-search-results__loading"><span class="spinner"></span> Mencari...</div>`;

                    try {
                        const res = await apiCall(`/api/admin/vrchat/search?q=${encodeURIComponent(query)}`);
                        if (!res.success) {
                            resultsEl.innerHTML = '';
                            toast(res.error || 'Pencarian VRChat gagal', 'error');
                            return;
                        }
                        renderVrchatSearchResults(res.results || []);
                    } catch (e) {
                        resultsEl.innerHTML = '';
                        toast('Pencarian VRChat gagal: ' + e.message, 'error');
                    }
                }

                function renderVrchatSearchResults(results) {
                    const resultsEl = $('#vrchatSearchResults');
                    const username = $('#editPlayerOldUsername').value;

                    if (results.length === 0) {
                        resultsEl.innerHTML = `<div class="vrchat-search-results__empty">Tidak ada hasil.</div>`;
                        return;
                    }

                    resultsEl.innerHTML = results.map(r => `
                        <button type="button" class="vrchat-candidate" onclick="window.appLinkVrchat(${jsStr(username)}, ${jsStr(r.id)}, ${jsStr(r.displayName)})">
                            ${avatarHtml(r.displayName, r.thumbnail)}
                            <span class="vrchat-candidate__name">${escHtml(r.displayName)}</span>
                        </button>`
                    ).join('');
                }

                window.appLinkVrchat = async function (username, vrchatUserId, displayName) {
                    try {
                        const res = await apiCall('/api/admin/vrchat/link', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username, vrchat_user_id: vrchatUserId })
                        });

                        if (res.success) {
                            toast(res.message || `Ditautkan ke '${displayName}'.`, 'success');
                            await fetchPlayers(true);
                            await fetchMatches(true);
                            // Linking renames the player to their VRChat name: reopen the
                            // modal under it, or Save would PATCH the old name back.
                            const player = playersCache.find(p => p.username === (res.username || username));
                            if (player) window.appOpenEditPlayer(player.username, player.rating);
                        } else {
                            toast(res.error || 'Gagal menautkan akun VRChat', 'error');
                        }
                    } catch (e) {
                        toast('Gagal menautkan akun VRChat: ' + e.message, 'error');
                    }
                };

                async function unlinkVrchat(username) {
                    if (!confirm(`Putuskan tautan VRChat untuk '${username}'?`)) return;
                    try {
                        const res = await apiCall('/api/admin/vrchat/unlink', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ username })
                        });

                        if (res.success) {
                            toast('Tautan VRChat dihapus.', 'success');
                            await fetchPlayers(true);
                            const player = playersCache.find(p => p.username === username) || null;
                            renderVrchatLinkStatus(player);
                        } else {
                            toast(res.error || 'Gagal memutuskan tautan', 'error');
                        }
                    } catch (e) {
                        toast('Gagal memutuskan tautan: ' + e.message, 'error');
                    }
                }

                async function refreshVrchatAvatars() {
                    try {
                        const res = await apiCall('/api/admin/vrchat/refresh-avatars', { method: 'POST' });
                        if (res.success) {
                            toast(res.message || 'Avatar VRChat diperbarui.', 'success');
                            await fetchPlayers(true);
                        } else {
                            toast(res.error || 'Gagal memperbarui avatar VRChat', 'error');
                        }
                    } catch (e) {
                        toast('Gagal memperbarui avatar VRChat: ' + e.message, 'error');
                    }
                }

                window.appDeletePlayer = async function (username) {
                    if (!confirm(`Hapus pemain '${username}'?`)) return;
                    try {
                        const res = await apiCall(`/api/players/${encodeURIComponent(username)}`, { method: 'DELETE' });
                        if (res.success) {
                            toast(`Pemain '${username}' telah dihapus.`, 'success');
                            $('#playerStatsModal').style.display = 'none';
                            await fetchPlayers(true);
                            await fetchMatches(true);
                        } else {
                            toast(res.error || 'Gagal menghapus pemain', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appOpenEditMatch = function (matchId, result, url) {
                    $('#editMatchIdTitle').textContent = matchId;
                    $('#editMatchId').value = matchId;
                    $('#editMatchResult').value = result;
                    $('#editMatchUrl').value = url;
                    $('#editMatchModal').style.display = 'flex';
                };

                window.appInvalidateMatch = async function (matchId) {
                    if (!confirm(`Anulir pertandingan #${matchId}?`)) return;
                    try {
                        const res = await apiCall(`/api/matches/${matchId}/invalidate`, { method: 'PUT' });
                        if (res.success) {
                            toast(`Match #${matchId} berhasil di-anulir!`, 'success');
                            await fetchPlayers(true);
                            await fetchMatches(true);
                        } else {
                            toast(res.error || 'Gagal anulir match', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appRevalidateMatch = async function (matchId) {
                    try {
                        const res = await apiCall(`/api/matches/${matchId}/revalidate`, { method: 'PUT' });
                        if (res.success) {
                            toast(`Match #${matchId} berhasil dipulihkan!`, 'success');
                            await fetchPlayers(true);
                            await fetchMatches(true);
                        } else {
                            toast(res.error || 'Gagal memulihkan match', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                window.appDeleteMatch = async function (matchId) {
                    if (!confirm(`Hapus pertandingan #${matchId}?`)) return;
                    try {
                        const res = await apiCall(`/api/matches/${matchId}`, { method: 'DELETE' });
                        if (res.success) {
                            toast(`Match #${matchId} telah dihapus.`, 'success');
                            await fetchPlayers(true);
                            await fetchMatches(true);
                        } else {
                            toast(res.error || 'Gagal menghapus match', 'error');
                        }
                    } catch (e) {
                        toast(e.message, 'error');
                    }
                };

                // ── Event Handlers Setup ──
                function setupEvents() {
                    // Login Modal
                    $('#btnOpenLoginModal').addEventListener('click', () => {
                        $('#loginUsername').value = '';
                        $('#loginPassword').value = '';
                        $('#loginErrorMsg').style.display = 'none';
                        $('#loginModal').style.display = 'flex';
                    });
                    $('#btnCloseLoginModal').addEventListener('click', () => { $('#loginModal').style.display = 'none'; });
                    $('#btnCancelLogin').addEventListener('click', () => { $('#loginModal').style.display = 'none'; });

                    $('#loginForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const username = $('#loginUsername').value || 'admin';
                        const password = $('#loginPassword').value;
                        $('#loginErrorMsg').style.display = 'none';

                        try {
                            const res = await apiCall('/api/admin/login', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ username, password })
                            });

                            if (res.success) {
                                currentAdminName = res.username;
                                setAdminState(true);
                                $('#loginModal').style.display = 'none';
                                toast('Selamat datang! Login admin berhasil.', 'success');
                                renderLeaderboard();
                                renderMatches();
                                fetchTokens(true);
                            } else {
                                $('#loginErrorMsg').textContent = res.error || 'Login gagal!';
                                $('#loginErrorMsg').style.display = 'block';
                            }
                        } catch (err) {
                            $('#loginErrorMsg').textContent = err.message || 'Login gagal!';
                            $('#loginErrorMsg').style.display = 'block';
                        }
                    });

                    // Logout Handler
                    $('#btnLogoutBtn').addEventListener('click', async () => {
                        try {
                            await apiCall('/api/admin/logout', { method: 'POST' });
                        } catch (e) { }
                        setAdminState(false);
                        currentAdminName = '';
                        toast('Logout berhasil.', 'info');
                        renderLeaderboard();
                        renderMatches();
                    });

                    // Create Admin
                    $('#btnShowCreateAdminModal').addEventListener('click', () => {
                        $('#createAdminUsername').value = '';
                        $('#createAdminPassword').value = '';
                        $('#createAdminModal').style.display = 'flex';
                    });
                    $('#btnCloseCreateAdminModal').addEventListener('click', () => { $('#createAdminModal').style.display = 'none'; });
                    $('#btnCancelCreateAdmin').addEventListener('click', () => { $('#createAdminModal').style.display = 'none'; });

                    $('#createAdminForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const username = $('#createAdminUsername').value.trim();
                        const password = $('#createAdminPassword').value;
                        try {
                            const res = await apiCall('/api/admin/admins', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ username, password })
                            });

                            if (res.success) {
                                toast(`Admin '${username}' berhasil dibuat!`, 'success');
                                $('#createAdminModal').style.display = 'none';
                                fetchAdmins(true);
                            } else {
                                toast(res.error || 'Gagal membuat admin', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Edit Admin Password
                    $('#btnCloseEditAdminModal').addEventListener('click', () => { $('#editAdminModal').style.display = 'none'; });
                    $('#btnCancelEditAdmin').addEventListener('click', () => { $('#editAdminModal').style.display = 'none'; });

                    $('#editAdminForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const username = $('#editAdminUsernameHidden').value;
                        const password = $('#editAdminPassword').value;
                        try {
                            const res = await apiCall(`/api/admin/admins/${encodeURIComponent(username)}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ username, password })
                            });

                            if (res.success) {
                                toast(`Password admin '${username}' berhasil diubah!`, 'success');
                                $('#editAdminModal').style.display = 'none';
                            } else {
                                toast(res.error || 'Gagal mengubah password', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Create Token
                    $('#btnShowCreateTokenModal').addEventListener('click', () => {
                        $('#tokenName').value = '';
                        $('#createTokenModal').style.display = 'flex';
                    });
                    $('#btnCloseCreateTokenModal').addEventListener('click', () => { $('#createTokenModal').style.display = 'none'; });
                    $('#btnCancelCreateToken').addEventListener('click', () => { $('#createTokenModal').style.display = 'none'; });

                    $('#createTokenForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const name = $('#tokenName').value.trim();
                        try {
                            const res = await apiCall('/api/admin/tokens', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ name })
                            });

                            if (res.success && res.token) {
                                toast(`API Token '${res.token.name}' berhasil dibuat!`, 'success');
                                $('#createTokenModal').style.display = 'none';
                                fetchTokens(true);
                            } else {
                                toast(res.error || 'Gagal membuat token', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Edit Token
                    $('#btnCloseEditTokenModal').addEventListener('click', () => { $('#editTokenModal').style.display = 'none'; });
                    $('#btnCancelEditToken').addEventListener('click', () => { $('#editTokenModal').style.display = 'none'; });

                    $('#editTokenForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const id = $('#editTokenId').value;
                        const name = $('#editTokenName').value.trim();
                        const isActive = $('#editTokenActive').value === '1';

                        try {
                            const res = await apiCall(`/api/admin/tokens/${encodeURIComponent(id)}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id, name, is_active: isActive })
                            });

                            if (res.success) {
                                toast(`Token berhasil diperbarui!`, 'success');
                                $('#editTokenModal').style.display = 'none';
                                fetchTokens(true);
                            } else {
                                toast(res.error || 'Gagal mengedit token', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Edit Player Form
                    $('#editPlayerForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const oldName = $('#editPlayerOldUsername').value;
                        const newName = $('#editPlayerUsername').value;
                        const newRating = parseInt($('#editPlayerRating').value) || 400;

                        try {
                            const res = await apiCall(`/api/players/${encodeURIComponent(oldName)}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ username: newName, rating: newRating })
                            });

                            if (res.success) {
                                toast('Data pemain berhasil diperbarui.', 'success');
                                $('#editPlayerModal').style.display = 'none';
                                await fetchPlayers(true);
                                await fetchMatches(true);
                            } else {
                                toast(res.error || 'Gagal memperbarui pemain', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    $('#btnCloseEditPlayerModal').addEventListener('click', () => { $('#editPlayerModal').style.display = 'none'; });
                    $('#btnCancelEditPlayer').addEventListener('click', () => { $('#editPlayerModal').style.display = 'none'; });

                    $('#btnVrchatSearch').addEventListener('click', searchVrchat);
                    $('#vrchatSearchInput').addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            searchVrchat();
                        }
                    });

                    if ($('#btnRefreshVrchatAvatars')) {
                        $('#btnRefreshVrchatAvatars').addEventListener('click', refreshVrchatAvatars);
                    }

                    // Edit Match Form
                    $('#editMatchForm').addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const matchId = $('#editMatchId').value;
                        const result = $('#editMatchResult').value;
                        const url = $('#editMatchUrl').value;

                        try {
                            const res = await apiCall(`/api/matches/${matchId}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ result: result, analysis_url: url })
                            });

                            if (res.success) {
                                toast(`Match #${matchId} berhasil diperbarui.`, 'success');
                                $('#editMatchModal').style.display = 'none';
                                await fetchPlayers(true);
                                await fetchMatches(true);
                            } else {
                                toast(res.error || 'Gagal memperbarui match', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    $('#btnCloseEditMatchModal').addEventListener('click', () => { $('#editMatchModal').style.display = 'none'; });
                    $('#btnCancelEditMatch').addEventListener('click', () => { $('#editMatchModal').style.display = 'none'; });

                    // Submit Play Match Form
                    $('#playPgnFile').addEventListener('change', (e) => {
                        const file = e.target.files[0];
                        if (!file) return;
                        const reader = new FileReader();
                        reader.onload = (ev) => { $('#playPgn').value = ev.target.result; };
                        reader.readAsText(file);
                    });
                    $('#btnClearPlayPgn').addEventListener('click', () => {
                        $('#playPgn').value = '';
                        $('#playPgnFile').value = '';
                    });

                    $('#playMatchForm').addEventListener('submit', async (e) => {
                        e.preventDefault();

                        const white = $('#playWhite').value.trim();
                        const black = $('#playBlack').value.trim();
                        const result = $('#playResult').value;
                        const pgn = $('#playPgn').value.trim();
                        const url = $('#playUrl').value.trim();

                        if (white.toLowerCase() === black.toLowerCase()) {
                            toast('Pemain Putih dan Hitam harus berbeda!', 'error');
                            return;
                        }

                        // Priority: PGN > URL > blank — resolved server-side in one request
                        const btn = $('#btnSubmitMatch');
                        btn.disabled = true;
                        btn.innerHTML = `<span class="spinner"></span> ${pgn ? 'Menyimpan PGN & Mencatat...' : 'Mencatat Pertandingan...'}`;

                        try {
                            const body = { white, black, result };
                            if (pgn) body.pgn = pgn;   // backend saves analysis internally
                            else if (url) body.url = url;   // use external URL as-is
                            // else: no analysis link — submitted with blank url

                            const res = await apiCall('/api/matches', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(body)
                            });

                            if (res.success && res.match) {
                                const m = res.match;
                                $('#playResultPanel').style.display = 'block';

                                $('#resWhiteName').textContent = m.white.username;
                                $('#resWhiteOld').textContent = m.white.old_rating;
                                $('#resWhiteNew').textContent = m.white.new_rating;
                                const wDiff = m.white.change;
                                $('#resWhiteChange').textContent = (wDiff >= 0 ? '+' : '') + wDiff;
                                $('#resWhiteChange').className = `result-player__change ${wDiff > 0 ? 'positive' : wDiff < 0 ? 'negative' : 'neutral'}`;

                                $('#resBlackName').textContent = m.black.username;
                                $('#resBlackOld').textContent = m.black.old_rating;
                                $('#resBlackNew').textContent = m.black.new_rating;
                                const bDiff = m.black.change;
                                $('#resBlackChange').textContent = (bDiff >= 0 ? '+' : '') + bDiff;
                                $('#resBlackChange').className = `result-player__change ${bDiff > 0 ? 'positive' : bDiff < 0 ? 'negative' : 'neutral'}`;

                                toast('Pertandingan berhasil dicatat!', 'success');
                                $('#playWhite').value = '';
                                $('#playBlack').value = '';
                                $('#playUrl').value = '';
                                $('#playPgn').value = '';
                                $('#playPgnFile').value = '';

                                await fetchPlayers(true);
                                await fetchMatches(true);
                                if (pgn) await fetchAnalyses(true);
                            }
                        } catch (err) {
                            toast(err.message || 'Gagal mencatat pertandingan', 'error');
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = `${ICONS.zap} Catat Pertandingan &amp; Hitung Ulang Rating`;
                        }
                    });

                    // Add Player Button
                    $('#btnAddPlayerBtn').addEventListener('click', () => {
                        window.appOpenEditPlayer('', 400);
                    });

                    // Modals Close Event Handlers
                    $('#btnClosePlayerStats').addEventListener('click', () => { $('#playerStatsModal').style.display = 'none'; });
                    $('#btnClosePlayerStatsBtn').addEventListener('click', () => { $('#playerStatsModal').style.display = 'none'; });
                    $('#btnEditPlayerModalAction').addEventListener('click', () => {
                        if (activePlayerUsername) {
                            const p = playersCache.find(x => x.username === activePlayerUsername);
                            if (p) window.appOpenEditPlayer(p.username, p.rating);
                        }
                    });
                    $('#btnDeletePlayerModalAction').addEventListener('click', () => {
                        if (activePlayerUsername) window.appDeletePlayer(activePlayerUsername);
                    });

                    // Player profile modal: internal tab switching
                    $$('.tab-nav--modal .modal-tab-btn').forEach(btn => {
                        btn.addEventListener('click', () => switchModalTab(btn.dataset.modalTab));
                    });

                    // Any modal: click the dimmed backdrop, or press Escape, to close it
                    $$('.modal-overlay').forEach(overlay => {
                        overlay.addEventListener('click', (e) => {
                            if (e.target === overlay) overlay.style.display = 'none';
                        });
                    });
                    document.addEventListener('keydown', (e) => {
                        if (e.key === 'Escape') {
                            $$('.modal-overlay').forEach(overlay => {
                                if (overlay.style.display === 'flex') overlay.style.display = 'none';
                            });
                        }
                    });

                    // Search & Filters
                    $('#playerSearch').addEventListener('input', (e) => renderLeaderboard(e.target.value));

                    if ($('#matchSearch')) {
                        $('#matchSearch').addEventListener('input', () => renderMatches());
                        $('#btnResetMatchFilters').addEventListener('click', () => {
                            $('#matchSearch').value = '';
                            renderMatches();
                        });
                    }

                    if ($('#analysisSearch')) {
                        $('#analysisSearch').addEventListener('input', () => renderAnalyses());
                        $('#btnResetAnalysisFilters').addEventListener('click', () => {
                            $('#analysisSearch').value = '';
                            renderAnalyses();
                        });
                    }

                    $('#filterAllMatches').addEventListener('click', () => {
                        currentMatchFilter = 'all';
                        $$('.filter-btn').forEach(b => b.classList.remove('active'));
                        $('#filterAllMatches').classList.add('active');
                        renderMatches();
                    });

                    $('#filterValidMatches').addEventListener('click', () => {
                        currentMatchFilter = 'valid';
                        $$('.filter-btn').forEach(b => b.classList.remove('active'));
                        $('#filterValidMatches').classList.add('active');
                        renderMatches();
                    });

                    $('#filterInvalidMatches').addEventListener('click', () => {
                        currentMatchFilter = 'invalid';
                        $$('.filter-btn').forEach(b => b.classList.remove('active'));
                        $('#filterInvalidMatches').classList.add('active');
                        renderMatches();
                    });

                    // Tab Navigation
                    $$('.tab-btn').forEach(btn => {
                        btn.addEventListener('click', () => {
                            const tab = btn.dataset.tab;
                            $$('.tab-btn').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');

                            $$('.tab-content').forEach(c => c.classList.remove('active'));
                            $(`#tab-${tab}`).classList.add('active');

                            if (tab === 'analysis') {
                                document.body.classList.add('analysis-tab-active');
                                setTimeout(() => {
                                    if (typeof analysisBoard !== 'undefined' && analysisBoard && $('#analysisMainLayout').style.display === 'block') {
                                        analysisBoard.redrawAll();
                                        // A batch review that ran while this tab was hidden drew its
                                        // arrows against a zero-sized board (NaN coordinates), so
                                        // redraw the position itself now that it has real pixels.
                                        // Skipped mid-run: analysisPositions is still being filled
                                        // in, and the review's own gotoMove(0) follows each game.
                                        if (!reanalysisRunning && analysisPositions.length) gotoMove(currentMoveIdx);
                                    }
                                }, 50);
                            } else {
                                document.body.classList.remove('analysis-tab-active');
                                clearArrows();
                            }
                        });
                    });

                    // Refresh buttons
                    $('#btnRefreshPlayers').addEventListener('click', () => fetchPlayers(false));
                    $('#btnRefreshAnalyses')?.addEventListener('click', () => fetchAnalyses(false));
                    $('#btnRefreshMatches').addEventListener('click', () => fetchMatches(false));
                    $('#btnRefreshGallery')?.addEventListener('click', async () => {
                        try {
                            const res = await apiCall('/api/admin/gallery/refresh', { method: 'POST' });
                            if (res.success) {
                                toast(res.message || 'Galeri VRChat diperbarui', 'success');
                                await fetchGalleryPhotos(true);
                            } else {
                                toast(res.error || 'Gagal memperbarui galeri VRChat', 'error');
                            }
                        } catch (e) {
                            toast(e.message, 'error');
                        }
                    });

                    // Upload a photo straight into whichever gallery the admin clicked
                    // "Upload Gambar" on (see window.appTriggerGalleryUpload).
                    $('#galleryUploadInput')?.addEventListener('change', async (e) => {
                        const file = e.target.files[0];
                        const galleryId = pendingUploadGalleryId;
                        e.target.value = '';
                        pendingUploadGalleryId = null;
                        if (!file || !galleryId) return;

                        toast('Mengunggah gambar ke VRChat...', 'info');
                        const formData = new FormData();
                        formData.append('file', file);

                        try {
                            const res = await apiCall(`/api/admin/gallery/${encodeURIComponent(galleryId)}/upload`, {
                                method: 'POST',
                                body: formData,
                            });
                            if (res.success) {
                                toast(res.message || 'Gambar berhasil diunggah.', 'success');
                                await fetchGalleryPhotos(true);
                            } else {
                                toast(res.error || 'Gagal mengunggah gambar', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Create Gallery Modal
                    $('#btnShowCreateGalleryModal')?.addEventListener('click', () => {
                        $('#createGalleryName').value = '';
                        $('#createGalleryDescription').value = '';
                        $('#createGalleryModal').style.display = 'flex';
                    });
                    $('#btnCloseCreateGalleryModal')?.addEventListener('click', () => { $('#createGalleryModal').style.display = 'none'; });
                    $('#btnCancelCreateGallery')?.addEventListener('click', () => { $('#createGalleryModal').style.display = 'none'; });

                    $('#createGalleryForm')?.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const name = $('#createGalleryName').value.trim();
                        const description = $('#createGalleryDescription').value.trim();
                        try {
                            const res = await apiCall('/api/admin/gallery/create-gallery', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ name, description }),
                            });
                            if (res.success) {
                                toast(`Galeri '${name}' berhasil dibuat.`, 'success');
                                $('#createGalleryModal').style.display = 'none';
                                await fetchGalleryPhotos(true);
                            } else {
                                toast(res.error || 'Gagal membuat galeri', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Newsletter: refresh
                    $('#btnRefreshNewsletter')?.addEventListener('click', async () => {
                        try {
                            const res = await apiCall('/api/admin/newsletter/refresh', { method: 'POST' });
                            if (res.success) {
                                toast(res.message || 'Newsletter diperbarui', 'success');
                                await fetchNewsletterPosts(true);
                            } else {
                                toast(res.error || 'Gagal memperbarui newsletter', 'error');
                            }
                        } catch (e) {
                            toast(e.message, 'error');
                        }
                    });

                    // Newsletter: attach/replace a post's image
                    $('#postImageInput')?.addEventListener('change', async (e) => {
                        const file = e.target.files[0];
                        const postId = pendingPostImageId;
                        e.target.value = '';
                        pendingPostImageId = null;
                        if (!file || !postId) return;

                        toast('Mengunggah gambar ke VRChat...', 'info');
                        const formData = new FormData();
                        formData.append('file', file);

                        try {
                            const res = await apiCall(`/api/admin/newsletter/posts/${encodeURIComponent(postId)}/image`, {
                                method: 'POST',
                                body: formData,
                            });
                            if (res.success) {
                                toast(res.message || 'Gambar berhasil diunggah.', 'success');
                                await fetchNewsletterPosts(true);
                            } else {
                                toast(res.error || 'Gagal mengunggah gambar', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Newsletter: Create Post Modal
                    $('#btnShowCreatePostModal')?.addEventListener('click', () => {
                        $('#createPostTitle').value = '';
                        $('#createPostText').value = '';
                        $('#createPostVisibility').value = 'group';
                        $('#createPostNotify').checked = false;
                        $('#createPostModal').style.display = 'flex';
                    });
                    $('#btnCloseCreatePostModal')?.addEventListener('click', () => { $('#createPostModal').style.display = 'none'; });
                    $('#btnCancelCreatePost')?.addEventListener('click', () => { $('#createPostModal').style.display = 'none'; });

                    $('#createPostForm')?.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const title = $('#createPostTitle').value.trim();
                        const text = $('#createPostText').value.trim();
                        const visibility = $('#createPostVisibility').value;
                        const sendNotification = $('#createPostNotify').checked;
                        try {
                            const res = await apiCall('/api/admin/newsletter/posts', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ title, text, visibility, send_notification: sendNotification }),
                            });
                            if (res.success) {
                                toast('Post berhasil dibuat.', 'success');
                                $('#createPostModal').style.display = 'none';
                                await fetchNewsletterPosts(true);
                            } else {
                                toast(res.error || 'Gagal membuat post', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // Newsletter: Edit Post Modal
                    $('#btnCloseEditPostModal')?.addEventListener('click', () => { $('#editPostModal').style.display = 'none'; });
                    $('#btnCancelEditPost')?.addEventListener('click', () => { $('#editPostModal').style.display = 'none'; });

                    $('#editPostForm')?.addEventListener('submit', async (e) => {
                        e.preventDefault();
                        const id = $('#editPostId').value;
                        const title = $('#editPostTitle').value.trim();
                        const text = $('#editPostText').value.trim();
                        const visibility = $('#editPostVisibility').value;
                        const sendNotification = $('#editPostNotify').checked;
                        try {
                            const res = await apiCall(`/api/admin/newsletter/posts/${encodeURIComponent(id)}`, {
                                method: 'PATCH',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ title, text, visibility, send_notification: sendNotification }),
                            });
                            if (res.success) {
                                toast('Post berhasil diperbarui.', 'success');
                                $('#editPostModal').style.display = 'none';
                                await fetchNewsletterPosts(true);
                            } else {
                                toast(res.error || 'Gagal memperbarui post', 'error');
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });

                    // ── Analysis Tab Logic ──────────────────────────────────────
                    let analysisBoard = null;
                    let analysisChess = null;
                    let mainPositions = [];       // Backup of the main PGN line
                    let isCurrentGame960 = false; // Flag for 960 mode
                    let initialClock = null;      // Extracted from TimeControl
                    let analysisPositions = [];   // Currently active line [{fen, move_san, score, score_cp, bestmove, pv}]
                    // Move tree, like lichess: each position's continuations — [0] the main line,
                    // the rest variations. Kept beside the positions rather than on them, so a saved
                    // analysis stays a flat main-line array.
                    const moveTree = new WeakMap();
                    let moveListLines = [];       // move-list button → the line ending at that move

                    function childrenOf(node) {
                        let kids = moveTree.get(node);
                        if (!kids) moveTree.set(node, kids = []);
                        return kids;
                    }

                    // Records the active line in the tree (a no-op once it's there).
                    function recordActiveLine() {
                        for (let i = 1; i < analysisPositions.length; i++) {
                            const parent = analysisPositions[i - 1], node = analysisPositions[i];
                            if (!parent || !node) return;
                            const kids = childrenOf(parent);
                            if (!kids.includes(node)) kids.push(node);
                        }
                    }
                    let analysisHeaders = {};
                    let analysisMovesSan = [];
                    let currentMoveIdx = 0;       // 0 = starting position
                    let evalChartInstance = null;
                    let liveAnalysisAbort = null;
                    // Off by default so browsing a reviewed game shows Game Review's evals instead of
                    // re-searching (and overwriting) them from depth 1. gotoMove switches it on for
                    // positions without an eval unless the user set it by hand (liveEngineByUser).
                    let isLiveEngineEnabled = false;
                    let liveEngineByUser = false;
                    let currentAnalysisId = null; // Tracks the ID if analysis was loaded from a saved link
                    let lichessPausedUntil = 0;   // Lichess asks for a full minute of silence after a 429

                    // ── Lichess cloud evaluations (Game Review's opening phase) ──

                    // Lichess writes castling as king-takes-rook ("e1h1"); Stockfish, and the
                    // arrow drawing and chess.js replay here, expect the king's destination
                    // ("e1g1"). "e1h1" can also be a rook or queen sliding along the back rank,
                    // so this replays the line and only rewrites genuine king moves. Stops at
                    // the first move it can't play, returning the legal prefix.
                    function normalizeLichessLine(fen, moves) {
                        const board = new window.Chess(fen);
                        const line = [];
                        for (const uci of String(moves).trim().split(/\s+/)) {
                            const from = uci.slice(0, 2);
                            let to = uci.slice(2, 4);
                            const piece = board.get(from);
                            if (piece && piece.type === 'k' && Math.abs(from.charCodeAt(0) - to.charCodeAt(0)) > 1) {
                                to = (to[0] > from[0] ? 'g' : 'c') + to[1];
                            }
                            const promotion = uci[4];
                            if (!board.move({ from, to, promotion })) break;
                            line.push(from + to + (promotion || ''));
                        }
                        return line;
                    }

                    // Maps a /api/cloud-eval response onto the position shape the engines produce.
                    // Lichess scores every line from White's side. The top-level score_cp is
                    // stored that way too, but `multipv` holds raw engine lines, which score from
                    // the side to move — converted here so a Lichess position reads exactly
                    // like an engine one. null when no usable line comes back.
                    function lichessEvalToPosition(fen, moveIndex, data) {
                        const turn = fen.split(' ')[1] || 'w';
                        const multipv = {};
                        (data.pvs || []).forEach((line, k) => {
                            const pv = normalizeLichessLine(fen, line.moves || '');
                            if (!pv.length) return;
                            const isMate = line.mate !== undefined;
                            const whiteSide = isMate ? line.mate : line.cp;
                            const sideToMove = turn === 'b' ? -whiteSide : whiteSide;
                            multipv[k + 1] = {
                                score: sideToMove,
                                score_type: isMate ? 'mate' : 'cp',
                                eval: 100 / (1 + Math.exp(-sideToMove / 120)),
                                pv,
                            };
                        });

                        const best = data.pvs?.[0];
                        if (!best || !multipv[1]) return null;

                        const isMate = best.mate !== undefined;
                        const scoreCp = isMate ? (best.mate > 0 ? 9999 : -9999) : best.cp;
                        return {
                            fen,
                            move_index: moveIndex,
                            score: isMate ? 'M' + Math.abs(best.mate) : scoreCp / 100,
                            score_cp: scoreCp,
                            score_type: isMate ? 'mate' : 'cp',
                            bestmove: multipv[1].pv[0],
                            pv: multipv[1].pv.slice(0, 6),
                            depth: data.depth,
                            multipv,
                        };
                    }

                    // Rating-aware move classification: if the PGN's White/Black name matches a
                    // registered player, use their actual rating to adjust how easily they earn
                    // Brilliant/Great/Excellent/etc. — chess.com's own docs describe these labels
                    // as rating-dependent ("more generous criteria for lower-rated players").
                    // null when there's no match, which is treated as perfectly neutral (no
                    // adjustment) rather than guessing a rating.
                    let analysisWhiteRating = null;
                    let analysisBlackRating = null;
                    const CLASSIFICATION_RATING_BASELINE = 400; // this app's own starting rating (see MatchManager::INITIAL_RATING) — neutral point, not standard chess Elo's 1500

                    function lookupPlayerRating(username) {
                        if (!username) return null;
                        const uname = String(username).trim().toLowerCase();
                        if (!uname) return null;
                        // A game played before a player's VRChat rename carries the old name in its PGN.
                        const names = p => [p.username, ...(p.previous_usernames || [])].map(n => (n || '').trim().toLowerCase());
                        const found = playersCache.find(p => (p.username || '').trim().toLowerCase() === uname)
                            || playersCache.find(p => names(p).includes(uname));
                        return found && typeof found.rating === 'number' ? found.rating : null;
                    }

                    // Refreshes analysisWhiteRating/analysisBlackRating from analysisHeaders —
                    // call this any time analysisHeaders.White/Black changes. Also updates the
                    // player-name tooltip so a matched rating (and its effect on classification)
                    // isn't a silent black box.
                    function updateAnalysisRatings() {
                        analysisWhiteRating = lookupPlayerRating(analysisHeaders.White);
                        analysisBlackRating = lookupPlayerRating(analysisHeaders.Black);

                        const ratingTitle = (rating) => rating !== null
                            ? `Terdaftar di database, rating ${rating} — memengaruhi klasifikasi langkah (Brilliant/Great/Blunder/dll).`
                            : '';
                        if ($('#infoWhite')) $('#infoWhite').title = ratingTitle(analysisWhiteRating);
                        if ($('#infoBlack')) $('#infoBlack').title = ratingTitle(analysisBlackRating);
                    }

                    // >1 = more generous (harder-to-reach bad labels, easier-to-reach good ones,
                    // matching a lower-rated player being cut more slack); <1 = stricter. Clamped
                    // so a very new (400) or very strong player never swings to an absurd extreme.
                    function classificationLeniency(rating) {
                        if (rating === null || rating === undefined) return 1;
                        return Math.max(0.6, Math.min(1.6, 1 + (CLASSIFICATION_RATING_BASELINE - rating) / 1000));
                    }

                    // Default: the in-browser engine (stockfish-web) — no network round-trip, so
                    // it's never at the mercy of the server being slow or overloaded. In "Both"
                    // mode the browser engine leads: the UI finishes as soon as IT is done, and
                    // the server (cloud) engine runs alongside purely as a non-blocking bonus —
                    // if it's lagging it simply hasn't contributed yet by the time we're done,
                    // never something we wait around for. A per-engine failure just means that
                    // engine contributes nothing for that position; there's no sticky "disabled"
                    // state to manually switch back from. enginePreference lets the user pin it
                    // to one engine only; 'client' (browser) is the default.
                    let clientEngineAvailable = null;    // capability cache: null=unprobed, true/false=detected
                    let enginePreference = localStorage.getItem('vrchess-engine-pref') || 'client'; // 'client' | 'both' | 'server'
                    const ENGINE_MODE_CYCLE = ['client', 'both', 'server'];
                    const ENGINE_MODE_LABEL = { both: 'Keduanya (Browser utama + Server bonus)', client: 'Browser saja', server: 'Server saja' };

                    // Probes (once) whether the browser can actually run the client engine.
                    async function probeClientEngine() {
                        if (clientEngineAvailable === null) {
                            try {
                                clientEngineAvailable = window.VRChessEngine ? await window.VRChessEngine.isAvailable() : false;
                            } catch (e) {
                                clientEngineAvailable = false;
                            }
                            if (clientEngineAvailable) {
                                console.info('[Engine] In-browser Stockfish (stockfish-web) is available.');
                            } else {
                                console.info('[Engine] In-browser Stockfish unavailable — the server engine will cover it.');
                                // enginePreference defaults to 'client' without ever going through the
                                // toggle's own availability guard — if this browser/hosting setup
                                // genuinely can't run it, pinning to "browser only" would silently
                                // analyze nothing. Fall back to the server rather than go dark.
                                if (enginePreference === 'client') {
                                    enginePreference = 'server';
                                    localStorage.setItem('vrchess-engine-pref', enginePreference);
                                }
                            }
                            updateEngineSourceUI();
                        }
                        return clientEngineAvailable;
                    }

                    // Reflects the user's choice + actual capability in the toolbar toggle button.
                    function updateEngineSourceUI() {
                        const btn = $('#btnEngineSource');
                        if (!btn) return;

                        // Kept short (full explanation lives in the tooltip) so this never forces
                        // horizontal overflow in the toolbar on narrow/foldable-width screens.
                        if (enginePreference === 'both') {
                            btn.innerHTML = `${ICONS.zap} Both`;
                            btn.style.color = '#7fa650';
                            btn.style.borderColor = 'rgba(127,166,80,0.5)';
                            btn.title = clientEngineAvailable === false
                                ? 'Mesin browser tidak tersedia — hanya server yang aktif. Klik untuk beralih mode.'
                                : 'Browser mesin utama (tidak pernah menunggu server); server menambah kedalaman di latar belakang tanpa memperlambat. Klik untuk beralih mode.';
                        } else if (enginePreference === 'client') {
                            btn.innerHTML = `${ICONS.monitor} Browser`;
                            btn.style.color = '#7fa650';
                            btn.style.borderColor = 'rgba(127,166,80,0.5)';
                            btn.title = 'Hanya mesin browser (stockfish-web). Klik untuk beralih mode.';
                        } else {
                            btn.innerHTML = `${ICONS.cloud} Server`;
                            btn.style.color = '#5b9bd5';
                            btn.style.borderColor = 'rgba(91,155,213,0.5)';
                            btn.title = 'Hanya Stockfish server. Klik untuk beralih mode.';
                        }
                    }

                    function getLegalMoves() {
                        const dests = new Map();
                        analysisChess.SQUARES.forEach(s => {
                            const ms = analysisChess.moves({ square: s, verbose: true });
                            if (ms.length) dests.set(s, ms.map(m => m.to));
                        });
                        return dests;
                    }

                    function toColor(chess) { return chess.turn() === 'w' ? 'white' : 'black'; }

                    function handleAnalysisMove(orig, dest, metadata) {
                        analysisChess.load(analysisPositions[currentMoveIdx].fen);
                        const move = analysisChess.move({ from: orig, to: dest, promotion: 'q' });
                        if (!move) {
                            analysisBoard.set({ fen: analysisPositions[currentMoveIdx].fen });
                            return;
                        }

                        // A move that already exists here (the game's own, or a variation played
                        // earlier) is reused with its eval; anything else becomes another branch
                        // rather than replacing the previous one.
                        const parent = analysisPositions[currentMoveIdx];
                        const newFen = analysisChess.fen();
                        let child = childrenOf(parent).find((c) => c.fen === newFen);
                        if (!child) {
                            child = { fen: newFen, move_san: move.san, score: null, score_cp: null, score_type: null, bestmove: null, pv: [] };
                            childrenOf(parent).push(child);
                        }
                        analysisPositions = [...analysisPositions.slice(0, currentMoveIdx + 1), child];
                        gotoMove(currentMoveIdx + 1);
                    }

                    function initAnalysisTab() {
                        if (typeof window.Chess === 'undefined' || typeof window.Chessground === 'undefined') {
                            setTimeout(initAnalysisTab, 200);
                            return;
                        }

                        analysisChess = new window.Chess();

                        // Init board with Chessground
                        analysisBoard = window.Chessground(document.getElementById('analysisBoard'), {
                            fen: 'start',
                            turnColor: 'white',
                            movable: {
                                color: 'both',
                                free: false,
                                dests: getLegalMoves(),
                                events: {
                                    after: handleAnalysisMove
                                }
                            }
                        });

                        // File input
                        $('#pgnFileInput').addEventListener('change', (e) => {
                            const file = e.target.files[0];
                            if (!file) return;
                            const reader = new FileReader();
                            reader.onload = (ev) => { $('#pgnInput').value = ev.target.result; };
                            reader.readAsText(file);
                            e.target.value = '';
                        });

                        // Start analysis button
                        $('#btnStartPgnAnalysis').addEventListener('click', startPgnAnalysis);
                        $('#btnStartFreeAnalysis').addEventListener('click', () => {
                            analysisPositions = [{
                                fen: 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1',
                                move_san: null, score: null, score_cp: null, bestmove: null, pv: []
                            }];
                            analysisMovesSan = [];
                            currentMoveIdx = 0;
                            analysisHeaders = { White: 'Player', Black: 'Player', Event: 'Free Analysis' };
                            updateAnalysisRatings();

                            $('#analysisPgnImportCard').style.display = 'none';
                            $('#analysisMainLayout').style.display = 'block';

                            $('#infoWhite').textContent = 'Pemain Bebas';
                            $('#infoBlack').textContent = 'Pemain Bebas';
                            $('#infoEvent').textContent = 'Analisis Live';
                            $('#infoDate').textContent = '';
                            $('#infoResult').textContent = '';

                            analysisChess.reset();
                            // Deferred so chessground has real dimensions before gotoMove(0) draws
                            // anything (the container just switched from display:none to block).
                            // gotoMove already kicks off analyzeLivePosition for the position it lands
                            // on, so no separate call is needed here.
                            setTimeout(() => {
                                if (analysisBoard) analysisBoard.redrawAll();
                                gotoMove(0);
                            }, 50);
                        });

                        // Nav buttons
                        $('#btnAnalysisFirst').addEventListener('click', () => gotoMove(0));
                        $('#btnAnalysisPrev').addEventListener('click', () => gotoMove(currentMoveIdx - 1));
                        $('#btnAnalysisNext').addEventListener('click', () => gotoMove(currentMoveIdx + 1));
                        $('#btnAnalysisLast').addEventListener('click', () => gotoMove(analysisPositions.length - 1));
                        $('#btnAnalysisFlip2').addEventListener('click', () => {
                            analysisBoard.toggleOrientation();
                            // Keep the player rows (name/clock/accuracy/stats) and eval bar in
                            // sync with which side is now visually on top/bottom.
                            const isFlipped = analysisBoard.state.orientation === 'black';
                            $('.board-column')?.classList.toggle('flipped', isFlipped);
                            drawBestMoveArrow();
                            // drawMoveAnnotationOnBoard() already computes its position from
                            // analysisBoard.state.orientation, but it's only invoked on move
                            // navigation — without this call the move-classification badge
                            // (Brilliant/Blunder/etc.) stays put at its pre-flip square instead
                            // of following the board.
                            drawMoveAnnotationOnBoard();
                        });

                        // New analysis — clear saved ID
                        $('#btnNewAnalysis').addEventListener('click', () => {
                            currentAnalysisId = null;
                            $('#analysisMainLayout').style.display = 'none';
                            $('#analysisPgnImportCard').style.display = '';
                            analysisPositions = [];
                            mainPositions = [];
                            clearArrows();
                        });

                        function getCurrentPgnText() {
                            let pgnText = $('#pgnInput').value.trim();
                            // If empty (e.g., from Free Play), attempt to generate from moves
                            if (!pgnText && analysisPositions.length > 1) {
                                try {
                                    let temp = new window.Chess();
                                    if (analysisHeaders && analysisHeaders.FEN) temp.load(analysisHeaders.FEN);
                                    for (let i = 1; i < analysisPositions.length; i++) {
                                        if (analysisPositions[i].move_san) {
                                            temp.move(analysisPositions[i].move_san);
                                        }
                                    }
                                    pgnText = temp.pgn();
                                } catch (e) {
                                    console.error('Failed to generate PGN', e);
                                }
                            }
                            return pgnText;
                        }

                        // Export PGN
                        $('#btnExportPgn').addEventListener('click', () => {
                            let pgnText = getCurrentPgnText();

                            if (!pgnText) {
                                toast('No PGN available to export.', 'error');
                                return;
                            }

                            const blob = new Blob([pgnText], { type: 'text/plain' });
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = 'vrchess_analysis.pgn';
                            document.body.appendChild(a);
                            a.click();
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                            toast('PGN Exported!', 'success');
                        });

                        // Save / Share analysis
                        $('#btnSaveAnalysis').addEventListener('click', async () => {
                            const btn = $('#btnSaveAnalysis');
                            const origText = btn.innerHTML;

                            // If we have an existing ID but also have fresh analysis positions,
                            // patch the DB entry so future visitors don't need to re-analyze
                            if (currentAnalysisId && mainPositions.length > 1) {
                                btn.innerHTML = '<span class="spinner"></span> Saving...';
                                btn.disabled = true;
                                try {
                                    await fetch(`/api/analyses/${encodeURIComponent(currentAnalysisId)}`, {
                                        method: 'PATCH',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ analysis: mainPositions })
                                    });
                                } catch (_) { /* non-fatal, still copy URL */ }
                                btn.innerHTML = origText;
                                btn.disabled = false;
                            }

                            // If already saved, just copy the link (with or without positions patch above)
                            if (currentAnalysisId) {
                                const shareUrl = window.location.origin + window.location.pathname + '?analysis=' + currentAnalysisId;
                                navigator.clipboard.writeText(shareUrl).then(() => {
                                    toast('Link copied to clipboard!', 'success');
                                }).catch(() => {
                                    toast('Share URL: ' + shareUrl, 'success');
                                });
                                return;
                            }

                            try {
                                btn.innerHTML = '<span class="spinner"></span> Saving...';
                                btn.disabled = true;

                                const pgn = getCurrentPgnText();
                                if (!pgn) {
                                    toast('No moves to save.', 'error');
                                    btn.innerHTML = origText;
                                    btn.disabled = false;
                                    return;
                                }

                                const res = await fetch('/api/analyses', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ pgn: pgn, analysis: mainPositions })
                                });
                                const data = await res.json();
                                if (data.success) {
                                    currentAnalysisId = data.id;
                                    const shareUrl = window.location.origin + window.location.pathname + '?analysis=' + data.id;
                                    navigator.clipboard.writeText(shareUrl).then(() => {
                                        toast('Link copied to clipboard!', 'success');
                                    }).catch(() => {
                                        toast('Analysis saved! URL: ' + shareUrl, 'success');
                                    });
                                } else {
                                    toast('Failed to save: ' + data.error, 'error');
                                }
                                btn.innerHTML = origText;
                                btn.disabled = false;
                            } catch (e) {
                                toast('Error saving analysis', 'error');
                                $('#btnSaveAnalysis').innerHTML = `${ICONS.link} Share Link`;
                                $('#btnSaveAnalysis').disabled = false;
                            }
                        });

                        // Live controls
                        const liveDepth = $('#liveDepthSelect');
                        const liveMultiPv = $('#liveMultiPvSelect');
                        const btnToggleEngine = $('#btnToggleLiveEngine');
                        const btnEngineSource = $('#btnEngineSource');

                        const triggerReanalysis = () => {
                            if (isLiveEngineEnabled && analysisPositions[currentMoveIdx]) {
                                analyzeLivePosition(currentMoveIdx, analysisPositions[currentMoveIdx].fen);
                            }
                        };

                        if (liveDepth) {
                            liveDepth.addEventListener('change', triggerReanalysis);
                            liveDepth.addEventListener('input', triggerReanalysis);
                        }
                        if (liveMultiPv) {
                            liveMultiPv.addEventListener('change', triggerReanalysis);
                        }
                        if (btnToggleEngine) {
                            btnToggleEngine.addEventListener('click', () => {
                                liveEngineByUser = !isLiveEngineEnabled;
                                setLiveEngineEnabled(!isLiveEngineEnabled);
                                triggerReanalysis();
                            });
                        }
                        if (btnEngineSource) {
                            btnEngineSource.addEventListener('click', async () => {
                                let next = ENGINE_MODE_CYCLE[(ENGINE_MODE_CYCLE.indexOf(enginePreference) + 1) % ENGINE_MODE_CYCLE.length];

                                // "Browser only" needs the client engine to actually work — everything
                                // else degrades gracefully on its own (server always covers "both",
                                // and "server only" never touches the client engine at all).
                                if (next === 'client') {
                                    if (clientEngineAvailable === null) await probeClientEngine();
                                    if (!clientEngineAvailable) {
                                        toast('Mesin browser tidak tersedia di perangkat ini — dilewati.', 'error');
                                        next = 'server';
                                    }
                                }

                                enginePreference = next;
                                localStorage.setItem('vrchess-engine-pref', enginePreference);
                                updateEngineSourceUI();
                                toast(`Mode mesin: ${ENGINE_MODE_LABEL[enginePreference]}.`, 'info');

                                triggerReanalysis();
                            });
                        }
                        // Kick off capability detection immediately so the toggle shows the
                        // real state (not just "Checking…") before the user's first analysis.
                        probeClientEngine();

                        // Keyboard navigation
                        document.addEventListener('keydown', (e) => {
                            if ($('#tab-analysis').classList.contains('active') && analysisPositions.length > 0) {
                                if (e.key === 'ArrowLeft') { e.preventDefault(); gotoMove(currentMoveIdx - 1); }
                                if (e.key === 'ArrowRight') { e.preventDefault(); gotoMove(currentMoveIdx + 1); }
                                if (e.key === 'ArrowUp') { e.preventDefault(); gotoMove(0); }
                                if (e.key === 'ArrowDown') { e.preventDefault(); gotoMove(analysisPositions.length - 1); }
                            }
                        });
                    }

                    function updateEngineStreamBadge(isComputing, depth = 0, targetDepth = 99, nps = 0, source = null) {
                        const badge = $('#engineStreamBadge');
                        const textEl = $('#engineStreamText');
                        if (!badge || !textEl) return;

                        if (!isComputing) {
                            badge.style.display = 'none';
                            return;
                        }

                        badge.style.display = 'inline-flex';
                        let npsStr = nps > 0 ? ` · ${(nps / 1000000).toFixed(1)}M nps` : '';
                        // source is 'client', 'server', or 'client+server' (both engines contributing)
                        const icons = (source || '').split('+').map((s) => s === 'client' ? ICONS.monitor : s === 'server' ? ICONS.cloud : '').join('');
                        let sourceStr = icons ? `${icons} · ` : '';
                        textEl.innerHTML = `${sourceStr}Depth ${depth}/${targetDepth}${npsStr}`;
                    }

                    // Streams one server-side analysis over SSE, feeding onUpdate the same
                    // {depth, score, score_type, bestmove, pv, multipv, nps} shape the client
                    // engine produces, so both sources can be merged by analyzeLivePosition.
                    async function runServerLive(fen, depth, multipv, signal, onUpdate) {
                        const res = await fetch('/api/engine/analyze/stream', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ fen, depth, multipv, stream: true, chess960: isCurrentGame960 }),
                            signal
                        });

                        if (!res.ok) {
                            const data = await res.json().catch(() => ({}));
                            throw new Error(data.error || 'Server error');
                        }

                        const reader = res.body.getReader();
                        const decoder = new TextDecoder();
                        let buffer = '';

                        while (true) {
                            const { done, value } = await reader.read();
                            if (done) break;

                            buffer += decoder.decode(value, { stream: true });
                            const lines = buffer.split('\n');
                            buffer = lines.pop(); // keep last partial line in buffer

                            for (const line of lines) {
                                const trimmed = line.trim();
                                if (!trimmed || !trimmed.startsWith('data: ')) continue;
                                let data;
                                try {
                                    data = JSON.parse(trimmed.substring(6));
                                } catch (e) {
                                    continue;
                                }
                                onUpdate(data);
                            }
                        }
                    }

                    // Runs the browser engine and the server engine on the same position at the
                    // same time (subject to enginePreference), and always displays whichever
                    // result is currently deepest — so the two "collaborate": you get the faster
                    // one's early feedback and the more thorough one's eventual depth, and a
                    // failure/unavailability in either one just means it contributes nothing for
                    // this position — never a sticky fallback that has to be "switched back".
                    function setLiveEngineEnabled(on) {
                        isLiveEngineEnabled = on;
                        const btn = $('#btnToggleLiveEngine');
                        if (btn) {
                            btn.style.color = on ? '#7fa650' : '#aaa';
                            btn.style.borderColor = on ? 'rgba(127,166,80,0.5)' : '#444';
                            btn.innerHTML = on ? `${ICONS.zap} Live: ON`
                                : '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg> Live: OFF';
                        }
                        if (!on) {
                            if (liveAnalysisAbort) liveAnalysisAbort.abort();
                            updateEngineStreamBadge(false);
                        }
                    }

                    async function analyzeLivePosition(idx, fen) {
                        if (!isLiveEngineEnabled) return;
                        if (liveAnalysisAbort) liveAnalysisAbort.abort();
                        liveAnalysisAbort = new AbortController();
                        const signal = liveAnalysisAbort.signal;
                        // Held by reference: the active line is rebuilt on navigation, so by the
                        // time a late update lands, index idx may belong to another position.
                        const node = analysisPositions[idx];
                        const isCurrent = () => analysisPositions[currentMoveIdx] === node;

                        const depth = parseInt($('#liveDepthSelect')?.value) || 99;
                        const multipv = parseInt($('#liveMultiPvSelect')?.value) || 1;

                        if (isCurrent() && (!node || node.score === null)) {
                            $('#posEvalBest').textContent = "Menghitung...";
                            $('#posEvalPv').textContent = "—";
                            clearArrows();
                        }

                        // Order matters: probe first, since it can self-correct enginePreference
                        // away from 'client' if this browser turns out not to support it (see
                        // probeClientEngine) — wantServer must be computed from that corrected value.
                        const willUseClient = (enginePreference !== 'server') && await probeClientEngine();
                        const wantServer = enginePreference !== 'client';

                        const attemptedSources = [];
                        if (willUseClient) attemptedSources.push('client');
                        if (wantServer) attemptedSources.push('server');
                        updateEngineStreamBadge(true, 1, depth, 0, attemptedSources.join('+'));

                        let lastRenderTime = 0;
                        let bestDepthSeen = -1;
                        const sourcesShown = new Set();

                        // Shared per-update handler — fed by whichever engine(s) are running.
                        // Only lets an update win if it's at least as deep as what's already
                        // displayed. Ties go to the browser engine — it's the prioritized one, so
                        // a same-depth server update never displaces what it already showed.
                        function applyLiveInfo(data, source) {
                            if (!node) return;
                            const d = data.depth || 0;
                            if (d < bestDepthSeen) return;
                            if (d === bestDepthSeen && source === 'server' && sourcesShown.has('client')) return;
                            bestDepthSeen = d;
                            sourcesShown.add(source);

                            let scoreDisplay = null;
                            let scoreCp = null;
                            if (data.score_type === 'cp') {
                                scoreCp = data.score;
                                const turn = fen.split(' ')[1] || 'w';
                                if (turn === 'b') scoreCp = -scoreCp;
                                scoreDisplay = scoreCp / 100;
                            } else if (data.score_type === 'mate') {
                                scoreCp = data.score > 0 ? 9999 : -9999;
                                const turn = fen.split(' ')[1] || 'w';
                                if (turn === 'b') scoreCp = -scoreCp;
                                scoreDisplay = "M" + Math.abs(data.score);
                            }

                            node.score = scoreDisplay;
                            node.score_cp = scoreCp;
                            node.score_type = data.score_type;
                            node.bestmove = data.bestmove;
                            node.pv = data.pv || [];
                            node.multipv = data.multipv || [];
                            node.depth = data.depth;
                            node.nps = data.nps;
                            node.live = true; // eval now comes from here, not Game Review

                            updateEngineStreamBadge(true, d, depth, data.nps || 0, [...sourcesShown].join('+'));

                            if (isCurrent()) {
                                if (scoreDisplay !== null && scoreDisplay !== undefined) {
                                    const isNum = typeof scoreDisplay === 'number';
                                    let display = isNum ? (scoreDisplay >= 0 ? `+${scoreDisplay.toFixed(2)}` : scoreDisplay.toFixed(2)) : String(scoreDisplay);
                                    $('#posEvalScore').textContent = display;
                                    $('#posEvalScore').style.color = isNum
                                        ? (scoreDisplay > 0.2 ? '#fff' : scoreDisplay < -0.2 ? 'var(--accent-red)' : '#fff')
                                        : '#fff';
                                    updateEvalBar(scoreCp);
                                }

                                if ($('#posEvalDepth')) $('#posEvalDepth').textContent = data.depth || depth;
                                $('#posEvalBest').textContent = data.bestmove || '—';

                                // Limit PV display to avoid it taking up too much vertical space
                                const pvText = data.pv && data.pv.length ? data.pv.join(' ') : '—';
                                $('#posEvalPv').textContent = pvText;

                                // Update arrow on board live as engine calculates!
                                drawBestMoveArrow();
                                drawMoveAnnotationOnBoard();
                            }

                            const now = Date.now();
                            if (now - lastRenderTime > 150) {
                                lastRenderTime = now;
                                renderMoveList(true);  // live patch only
                                renderEvalChart();
                            }
                        }

                        // Built lazily so pure "browser only" mode can still reach for the server
                        // as an emergency, per-position fallback if the browser engine genuinely
                        // fails — it just never gets started proactively in that mode.
                        function startCloudTask() {
                            return runServerLive(fen, depth, multipv, signal, (d) => applyLiveInfo(d, 'server'))
                                .then(() => true)
                                .catch((e) => {
                                    if (e.name === 'AbortError') return null;
                                    console.error('Server engine failed for this position:', e);
                                    return false;
                                });
                        }

                        let localTask = null;
                        let cloudTask = wantServer ? startCloudTask() : null;

                        if (willUseClient) {
                            localTask = window.VRChessEngine.analyze(
                                fen, { depth, multipv, chess960: isCurrentGame960 },
                                (d) => applyLiveInfo(d, 'client'), signal
                            )
                                .then((r) => { applyLiveInfo(r, 'client'); return true; })
                                .catch((e) => {
                                    if (e.name === 'AbortError') return null;
                                    console.warn('Client engine failed for this position (server will still cover it if enabled):', e);
                                    return false;
                                });
                        }

                        const finish = () => {
                            if (isCurrent()) {
                                updateEngineStreamBadge(false);
                                renderMoveList();
                                renderEvalChart();
                            }
                        };

                        if (localTask) {
                            // Browser leads: the UI is done as soon as IT is, regardless of
                            // whether the server has responded yet — a lagging cloud engine never
                            // holds this up.
                            const localOk = await localTask;

                            if (localOk === false) {
                                // The browser engine genuinely failed on this position — make sure
                                // the server actually gets a shot at it, even in "browser only" mode.
                                if (!cloudTask) cloudTask = startCloudTask();
                                const cloudOk = await cloudTask;
                                finish();
                                if (cloudOk === false) toast('Analisis gagal di semua mesin yang aktif.', 'error');
                                return;
                            }

                            finish();

                            // If the server is still going, let it keep working in the background —
                            // if it eventually lands a deeper result for a position the user is
                            // still looking at, quietly refine the display. Never reopens the
                            // "computing" badge for this.
                            if (cloudTask) {
                                cloudTask.then((ok) => {
                                    if (ok && isCurrent()) { renderMoveList(); renderEvalChart(); }
                                });
                            }
                            return;
                        }

                        if (cloudTask) {
                            const cloudOk = await cloudTask;
                            finish();
                            if (cloudOk === false) toast('Analisis gagal di mesin server.', 'error');
                            return;
                        }

                        finish();
                    }

                    function clearArrows() {
                        if (analysisBoard) analysisBoard.set({ drawable: { shapes: [] } });
                    }

                    function drawBestMoveArrow() {
                        clearArrows();
                        if (analysisPositions.length === 0 || !analysisBoard) return;
                        const pos = analysisPositions[currentMoveIdx];
                        if (!pos) return;

                        let shapes = [];

                        // Try engine bestmove first
                        let bm = pos.bestmove;
                        if (!bm || bm.length < 4 || bm.includes('(none)')) {
                            // Fallback to PV if bestmove is missing
                            if (pos.pv && pos.pv.length > 0 && pos.pv[0].length >= 4 && !pos.pv[0].includes('(none)')) {
                                bm = pos.pv[0];
                            }
                        }

                        if (bm && bm.length >= 4 && !bm.includes('(none)')) {
                            shapes.push({ orig: bm.substring(0, 2), dest: bm.substring(2, 4), brush: 'green' });
                        }

                        // Add alternative lines (multipv >= 2)
                        if (pos.multipv) {
                            // Support up to 5 lines as configured in the frontend
                            for (let i = 2; i <= 5; i++) {
                                if (pos.multipv[i] && pos.multipv[i].pv && pos.multipv[i].pv.length > 0) {
                                    let altMove = pos.multipv[i].pv[0];
                                    if (altMove && altMove.length >= 4 && altMove !== bm) {
                                        shapes.push({ orig: altMove.substring(0, 2), dest: altMove.substring(2, 4), brush: 'blue' });
                                    }
                                }
                            }
                        }

                        if (shapes.length > 0) {
                            analysisBoard.set({
                                drawable: {
                                    shapes: shapes
                                }
                            });
                        }
                    }

                    // ── Move-classification helpers (see getMoveAnnotationIcon) ──
                    const MATERIAL = { p: 1, n: 3, b: 3, r: 5, q: 9, k: 0 };
                    const boardOf = fen => fen.split(' ')[0];

                    // Piece balance (knights, bishops, rooks, queens — pawns left out, since
                    // Brilliant is about piece sacrifices) from `color`'s side: theirs minus the
                    // opponent's. Counting only the mover's own pieces, as this used to, made
                    // every ordinary trade look like a sacrifice: BxN, then the recapture, "lost"
                    // a bishop with the captured knight never counted.
                    function pieceBalance(fen, color) {
                        let balance = 0;
                        for (const ch of boardOf(fen)) {
                            const value = MATERIAL[ch.toLowerCase()];
                            if (!value || ch === 'p' || ch === 'P') continue;
                            balance += (ch === ch.toUpperCase()) === (color === 'w') ? value : -value;
                        }
                        return balance;
                    }

                    // The move that led from prevPos to currPos, as UCI (e.g. "e2e4", "e7e8q").
                    // Positions only carry SAN, and engine lines are UCI, so the legal move whose
                    // resulting board matches currPos is the played move. Cached per position.
                    const playedUciCache = new WeakMap();
                    function playedMoveUci(prevPos, currPos) {
                        if (!prevPos?.fen || !currPos?.fen) return null;
                        if (playedUciCache.has(currPos)) return playedUciCache.get(currPos);
                        let found = null;
                        try {
                            const board = new window.Chess(prevPos.fen);
                            const target = boardOf(currPos.fen);
                            for (const m of board.moves({ verbose: true })) {
                                board.move(m);
                                const hit = boardOf(board.fen()) === target;
                                board.undo();
                                if (hit) { found = m.from + m.to + (m.promotion || ''); break; }
                            }
                        } catch (e) { }
                        playedUciCache.set(currPos, found);
                        return found;
                    }

                    // `color`'s piece balance after playing `plies` moves of an engine line from
                    // fen; null if the line is shorter or doesn't apply.
                    function balanceAlongLine(fen, line, plies, color) {
                        if (!line || line.length < plies) return null;
                        try {
                            const board = new window.Chess(fen);
                            for (const uci of line.slice(0, plies)) {
                                if (!board.move({ from: uci.slice(0, 2), to: uci.slice(2, 4), promotion: uci[4] })) return null;
                            }
                            return pieceBalance(board.fen(), color);
                        } catch (e) {
                            return null;
                        }
                    }

                    // A piece the mover just put where the opponent can take it at a profit —
                    // an offered sacrifice, which can be best play even when taking it is a
                    // mistake (so the engine's reply declines it). Material the opponent nets
                    // by capturing, after our recapture if there is one, minus anything this
                    // move itself captured.
                    function offeredMaterial(prevPos, currPos, played) {
                        try {
                            const square = played.slice(2, 4);
                            const before = new window.Chess(prevPos.fen);
                            // Valued as it stood before the move: a pawn that just promoted only
                            // risks a pawn, however big the piece it became.
                            const moved = before.get(played.slice(0, 2));
                            if (!moved || !MATERIAL[moved.type] || moved.type === 'p') return 0;
                            const took = before.get(square);
                            const tookValue = took ? MATERIAL[took.type] : 0;
                            const board = new window.Chess(currPos.fen);
                            let best = 0;
                            for (const capture of board.moves({ verbose: true }).filter(m => m.to === square)) {
                                board.move(capture);
                                const canRecapture = board.moves({ verbose: true }).some(m => m.to === square);
                                board.undo();
                                const net = MATERIAL[moved.type] - (canRecapture ? MATERIAL[capture.piece] : 0) - tookValue;
                                best = Math.max(best, net);
                            }
                            return best;
                        } catch (e) {
                            return 0;
                        }
                    }

                    // The mover gives up at least a minor piece's worth (SACRIFICE_MIN): either the
                    // engine's line takes it (and it's still gone at the end of the line, up to
                    // three plies, so it isn't a trade or a quick regain), or the moved piece is
                    // left hanging. An exchange (rook for a minor piece, 2) doesn't count: it's
                    // usually a capture made to simplify, which chess.com doesn't call a piece
                    // sacrifice. Engine lines can be as short as two plies — a trade, capture and
                    // recapture — so the later check uses however much of the line there is.
                    const SACRIFICE_MIN = 3;
                    // Mover's win% at or above which a big drop is a Miss, not a Mistake/Blunder:
                    // ~+2.3 pawns, a clearly winning position (see getMoveAnnotationIcon).
                    const MISS_STILL_WINNING = 70;
                    // Mover's win% at or above which a move is never a Blunder (~+0.5 pawns).
                    const STILL_BETTER = 55;
                    function isPieceSacrifice(prevPos, currPos, played, color) {
                        const before = pieceBalance(prevPos.fen, color);
                        const afterReply = balanceAlongLine(currPos.fen, currPos.pv, 1, color);
                        const laterPlies = Math.min(3, currPos.pv?.length || 0);
                        const afterLater = laterPlies > 1 ? balanceAlongLine(currPos.fen, currPos.pv, laterPlies, color) : afterReply;
                        if (afterReply !== null && afterLater !== null
                            && before - afterReply >= SACRIFICE_MIN && before - afterLater >= SACRIFICE_MIN) return true;
                        return offeredMaterial(prevPos, currPos, played) >= SACRIFICE_MIN;
                    }

                    // Moves that are often the only good one but never hard to find, so they stay
                    // Best rather than Great: delivering mate, taking back on the square the
                    // opponent just captured on, taking material the opponent can't recapture,
                    // and promoting.
                    function isObviousMove(prevPrevPos, prevPos, currPos, played) {
                        if (played.length === 5) return true;
                        try {
                            if (String(currPos.move_san || '').endsWith('#')) return true;
                            const after = new window.Chess(currPos.fen);
                            if (after.in_checkmate ? after.in_checkmate() : after.isCheckmate()) return true;
                            const square = played.slice(2, 4);
                            if (!new window.Chess(prevPos.fen).get(square)) return false; // not a capture
                            const opponentMove = playedMoveUci(prevPrevPos, prevPos);
                            if (opponentMove && opponentMove.slice(2, 4) === square && new window.Chess(prevPrevPos.fen).get(square)) {
                                return true; // recapture
                            }
                            return !new window.Chess(currPos.fen).moves({ verbose: true }).some(m => m.to === square);
                        } catch (e) {
                            return false;
                        }
                    }

                    // Mover's win% for the engine's first and second lines from prevPos; null when
                    // the second line is missing (an older single-line analysis, or only one legal move).
                    function topTwoLinePcts(prevPos) {
                        const lines = prevPos?.multipv;
                        const pv1 = lines?.['1'] || lines?.[1];
                        const pv2 = lines?.['2'] || lines?.[2];
                        if (!pv1 || !pv2 || pv1.score === null || pv2.score === null) return null;
                        // Engine lines score from the side to move, which here is the mover.
                        const pct = line => getWinPct(line.score_type === 'mate' ? (line.score > 0 ? 9999 : -9999) : line.score);
                        return [pct(pv1), pct(pv2)];
                    }

                    function getWinPct(cp) {
                        if (cp === 9999) return 100;
                        if (cp === -9999) return 0;
                        return 100 / (1 + Math.exp(-0.00368208 * cp));
                    }

                    function playerWinPct(cp, turn) {
                        let wPct = getWinPct(cp);
                        return turn === 'w' ? wPct : (100 - wPct);
                    }

                    // playedSan/wasInCheck are optional — omit them to fall back to the plain
                    // win%-only comparison.
                    function getMoveAccuracy(prevPct, nextPct, playedSan, wasInCheck) {
                        if (nextPct >= prevPct) {
                            if (prevPct > 50) return 100; // holding an advantage or equal position: full credit

                            // Already behind: "didn't get worse" isn't the same as "played well".
                            // A move that gives check or is itself a forced reply to being in check
                            // still counts as fighting/forced; still get full taper credit for it.
                            // Otherwise it's a voluntary, purely passive move — no threat created,
                            // no problems posed for the opponent — and scores lower still, since
                            // that's exactly the "just surviving instead of creating chances" pattern
                            // that let a single blunder's aftermath (king shuffling, forced recaptures,
                            // never a single check offered back) score as if it were fine play.
                            const isActiveOrForced = wasInCheck || (playedSan && /[+#]$/.test(playedSan));
                            return isActiveOrForced ? (50 + prevPct) : (25 + prevPct * 0.5);
                        }
                        const winDiff = prevPct - nextPct;
                        const raw = 103.1668100711649 * Math.exp(-0.04354415386753951 * winDiff) - 3.166924740191411;
                        return Math.max(0, Math.min(100, raw + 1));
                    }

                    //https://lichess.org/page/accuracy
                    // positions defaults to the currently-loaded analysis; pass an explicit array
                    // to score a different (e.g. historical) game without touching global state.
                    function computeGameAccuracy(color, positions) {
                        if (!positions) positions = analysisPositions;
                        if (!positions || positions.length <= 1) return '-';
                        const cps = [0]; // Initial state eval is ~0
                        for (let i = 1; i < positions.length; i++) {
                            cps.push(positions[i].score_cp || 0);
                        }

                        const allWinPercents = cps.map(cp => getWinPct(cp));
                        const n = cps.length;
                        let windowSize = Math.floor(n / 10);
                        windowSize = Math.max(2, Math.min(8, windowSize));

                        const windows = [];
                        const firstWindow = allWinPercents.slice(0, windowSize);
                        const copiesCount = Math.max(0, Math.min(windowSize, allWinPercents.length) - 2);

                        for (let i = 0; i < copiesCount; i++) windows.push(firstWindow);
                        for (let i = 0; i <= allWinPercents.length - windowSize; i++) {
                            windows.push(allWinPercents.slice(i, i + windowSize));
                        }

                        const stdDev = (arr) => {
                            if (!arr || arr.length === 0) return 0;
                            const mean = arr.reduce((a, b) => a + b, 0) / arr.length;
                            const variance = arr.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / arr.length;
                            return Math.sqrt(variance);
                        };

                        const weights = windows.map(w => Math.max(0.5, Math.min(12, stdDev(w) || 0)));
                        const weightedAccuracies = [];

                        for (let i = 0; i < allWinPercents.length - 1; i++) {
                            const isWhite = (i % 2 === 0);
                            const prev = allWinPercents[i];
                            const next = allWinPercents[i + 1];
                            const weight = weights[i] || 0.5;

                            let prevP = isWhite ? prev : (100 - prev);
                            let nextP = isWhite ? next : (100 - next);

                            const playedSan = positions[i + 1] ? positions[i + 1].move_san : null;
                            const precedingSan = positions[i] ? positions[i].move_san : null;
                            const wasInCheck = !!(precedingSan && /[+#]$/.test(precedingSan));

                            const acc = getMoveAccuracy(prevP, nextP, playedSan, wasInCheck);

                            if ((isWhite && color === 'w') || (!isWhite && color === 'b')) {
                                weightedAccuracies.push({ acc, weight });
                            }
                        }

                        if (weightedAccuracies.length === 0) return '-';

                        let sumWeight = 0;
                        let sumWeightedAcc = 0;
                        let sumHarmonic = 0;

                        for (let { acc, weight } of weightedAccuracies) {
                            sumWeightedAcc += acc * weight;
                            sumWeight += weight;
                            sumHarmonic += 1 / Math.max(1, acc);
                        }

                        const weightedMean = sumWeight > 0 ? (sumWeightedAcc / sumWeight) : 0;
                        const harmonicMean = weightedAccuracies.length / sumHarmonic;

                        return ((weightedMean + harmonicMean) / 2).toFixed(1);
                    }
                    // Counts Brilliant-classified moves for `color` across a saved-analysis
                    // positions array, using the player's own rating (matched by username, same
                    // lookup as live classification) so this stays consistent with what they'd
                    // see if they re-opened the game in the Analysis tab.
                    function countBrilliantMoves(positions, color, username) {
                        if (!positions || positions.length <= 1) return 0;
                        const leniency = classificationLeniency(lookupPlayerRating(username));
                        let count = 0;
                        for (let i = 1; i < positions.length; i++) {
                            const turn = i % 2 === 1 ? 'w' : 'b';
                            if (turn !== color) continue;
                            const prevPos = positions[i - 1];
                            const currPos = positions[i];
                            if (!prevPos || !currPos) continue;
                            const prevPrevPos = i >= 2 ? positions[i - 2] : null;
                            const moveNum = Math.ceil(i / 2);
                            const ann = getMoveAnnotationIcon(prevPos.score_cp, currPos.score_cp, turn, moveNum, prevPos, currPos, prevPrevPos, leniency);
                            if (ann && ann.src === window.MOVE_ICON_URLS.brilliant) count++;
                        }
                        return count;
                    }

                    // Average accuracy + total Brilliant count for `username`, across their own
                    // matches (from `history`, as built by buildPlayerHistory) whose PGN was
                    // actually run through the engine and saved — i.e. has stored per-position
                    // evaluations. Matches with no saved analysis, or one pointing at an external
                    // URL (chessigma, etc.), are skipped rather than guessed at; gamesAnalyzed
                    // tells the caller how many of the player's games this is actually based on.
                    // Bridged onto window (see window.gotoMoveLine for the same pattern) since
                    // openPlayerStats/refreshPlayerAnalysisStats live outside setupEvents().
                    async function computePlayerAnalysisStats(username, history) {
                        const candidates = history
                            .map(h => ({ h, id: extractInternalAnalysisId(h.analysisUrl) }))
                            .filter(x => x.id);

                        if (candidates.length === 0) return { avgAccuracy: null, brilliants: 0, gamesAnalyzed: 0 };

                        const results = await Promise.all(candidates.map(async ({ h, id }) => {
                            try {
                                const res = await apiCall(`/api/analyses/${encodeURIComponent(id)}`);
                                const positions = res && res.data && res.data.analysis;
                                if (!res.success || !Array.isArray(positions) || positions.length <= 1) return null;
                                const color = h.color === 'white' ? 'w' : 'b';
                                const accuracy = parseFloat(computeGameAccuracy(color, positions));
                                if (isNaN(accuracy)) return null;
                                return { accuracy, brilliants: countBrilliantMoves(positions, color, username) };
                            } catch (e) {
                                return null;
                            }
                        }));

                        const valid = results.filter(Boolean);
                        if (valid.length === 0) return { avgAccuracy: null, brilliants: 0, gamesAnalyzed: 0 };

                        const avgAccuracy = valid.reduce((sum, r) => sum + r.accuracy, 0) / valid.length;
                        const totalBrilliants = valid.reduce((sum, r) => sum + r.brilliants, 0);
                        return { avgAccuracy: avgAccuracy.toFixed(1), brilliants: totalBrilliants, gamesAnalyzed: valid.length };
                    }
                    window.computePlayerAnalysisStats = computePlayerAnalysisStats;

                    // Mirrors chess.com's move-classification model (support.chess.com/en/articles/8572705),
                    // checked in chess.com's order:
                    //   Book       — the move is opening theory (masters database via /api/opening-book,
                    //                or the bundled named-opening list for analyses without that).
                    //   Brilliant  — a sound piece sacrifice: at most Excellent-level loss, a real
                    //                piece given up (not a trade), not completely winning without it,
                    //                not worse after.
                    //   Great      — the engine's top move when it's the only move that holds: the
                    //                second-best line is clearly worse, and the move itself isn't
                    //                losing. Obvious ones (recaptures, taking free material,
                    //                promoting) are left to Best.
                    //   Miss       — a missed winning chance: a big drop that still leaves the mover
                    //                clearly winning, or failing to punish the opponent's mistake.
                    //   Best       — exactly the engine's top move (one per position, never a tie).
                    //   Excellent / Good / Inaccuracy / Mistake / Blunder — every other move, by
                    //                win% lost (chess.com's expected-points bands ×100).
                    // prevPrevPos (2 plies back) is optional — only Miss and the recapture check use
                    // it. leniency (see classificationLeniency) is 1 by default; the thresholds
                    // scale with it, so a matched player's rating shifts the ladder for their moves.
                    function getMoveAnnotationIcon(prevCp, currCp, turn, moveNum, prevPos, currPos, prevPrevPos, leniency) {
                        if (prevCp === null || prevCp === undefined || currCp === null || currCp === undefined) return null;
                        if (leniency === null || leniency === undefined) leniency = 1;

                        const prevPct = playerWinPct(prevCp, turn);
                        const currPct = playerWinPct(currCp, turn);
                        const loss = prevPct - currPct; // win% the mover gave up

                        // Book. A review records currPos.book from the masters database; older
                        // analyses (book undefined) fall back to the bundled named-opening list.
                        // Theory is theory, so this isn't rating-adjusted.
                        if (currPos.book === true) {
                            return { src: window.MOVE_ICON_URLS.theoritical_move, title: 'Book: A known opening move' };
                        }
                        if (currPos.book === undefined && !isCurrentGame960 && currPos.fen && openingAt(currPos.fen)) {
                            return { src: window.MOVE_ICON_URLS.theoritical_move, title: 'Book: A known opening move' };
                        }

                        const played = playedMoveUci(prevPos, currPos);
                        const engineBest = prevPos.bestmove && prevPos.bestmove !== '(none)' ? prevPos.bestmove : null;
                        const isBest = !!played && played === engineBest;

                        // Engine's first and second lines (Game Review always computes two — see
                        // startPgnAnalysis); null for older single-line analyses.
                        const lines = topTwoLinePcts(prevPos);

                        // Brilliant: not in a bad position afterward, and — chess.com's wording —
                        // not completely winning even without the sacrifice. That's judged by the
                        // best move that ISN'T this one: Morphy's 16.Qb8+ is mate, while every
                        // other move scores ~77-87% depending on the engine, so it's Brilliant.
                        // Both bounds loosen for a lower-rated player and tighten for a higher one.
                        const brilliantFloor = Math.max(20, Math.min(60, 45 / leniency));
                        const brilliantCeil = Math.max(80, Math.min(95, 90 * leniency));
                        const withoutSacrifice = !lines ? prevPct : (isBest ? lines[1] : lines[0]);
                        if (played && loss < 2 * leniency && currPct >= brilliantFloor && withoutSacrifice < brilliantCeil
                            && isPieceSacrifice(prevPos, currPos, played, turn)) {
                            return { src: window.MOVE_ICON_URLS.brilliant, title: "Brilliant: A sound sacrifice" };
                        }

                        if (isBest && !isObviousMove(prevPrevPos, prevPos, currPos, played)) {
                            const requiredGap = Math.max(12, Math.min(35, 20 / leniency));
                            if (lines && lines[0] >= 40 && lines[0] - lines[1] >= requiredGap) {
                                return { src: window.MOVE_ICON_URLS.the_only_move, title: "Great: The only good move" };
                            }
                        }

                        // Miss — a missed chance rather than a collapse. Two cases, each replacing
                        // what would otherwise be a Mistake or Blunder:
                        // 1. A Mistake-sized chunk (or more) given up while the mover is still
                        //    clearly winning afterward (MISS_STILL_WINNING, about +2.3): missing a
                        //    forced mate but staying at +5, or dropping +8 to +2.5. Calling those
                        //    Blunders punished a missed tactic like a lost game.
                        if (loss >= 10 * leniency && currPct >= MISS_STILL_WINNING) {
                            return { src: window.MOVE_ICON_URLS.missed_tactic, title: "Miss: Missed a winning chance" };
                        }
                        // 2. The opponent handed you a big swing in your favor (their previous
                        //    move was a mistake) and instead of capitalizing, this move gives a
                        //    meaningful chunk of it back. A more generous leniency requires a
                        //    bigger gift squandered before it counts against you.
                        if (prevPrevPos && prevPrevPos.score_cp !== null && prevPrevPos.score_cp !== undefined) {
                            const pctTwoAgo = playerWinPct(prevPrevPos.score_cp, turn);
                            const opponentGift = prevPct - pctTwoAgo;
                            const requiredGift = Math.max(8, Math.min(30, 15 * leniency));
                            const requiredMissLoss = Math.max(6, Math.min(18, 10 * leniency));
                            if (opponentGift >= requiredGift && prevPct >= 60 && loss >= requiredMissLoss) {
                                return { src: window.MOVE_ICON_URLS.missed_tactic, title: "Miss: Missed a winning chance" };
                            }
                        }

                        if (isBest) {
                            return { src: window.MOVE_ICON_URLS.best_move, title: "Best: The engine's top choice" };
                        }

                        // Every other move, by win% lost: Excellent under 2, Good 2-5, Inaccuracy
                        // 5-10, Mistake 10-20, Blunder 20+, each cutoff scaled by leniency. A move
                        // that loses nothing but isn't the engine's choice is Excellent, not Best.
                        // A Blunder hands the game away; a move that still leaves the mover better
                        // (about +0.5 or more) is at worst a Mistake.
                        if (loss >= 20 * leniency && currPct < STILL_BETTER) {
                            return { src: window.MOVE_ICON_URLS.blunder, title: "Blunder: Extremely costly mistake" };
                        }
                        if (loss >= 10 * leniency) {
                            return { src: window.MOVE_ICON_URLS.strange_move, title: "Mistake: Meaningful chunk of game lost" };
                        }
                        if (loss >= 5 * leniency) {
                            return { src: window.MOVE_ICON_URLS.tactical_move, title: "Inaccuracy: Slightly worsens position" };
                        }
                        if (loss >= 2 * leniency) {
                            return { src: window.MOVE_ICON_URLS.good_move, title: "Good: A solid, sensible move" };
                        }
                        // Positions saved without an engine best move (old analyses) can't tell
                        // Best from Excellent, so a near-zero loss reads as Best, as it used to.
                        if (!engineBest && loss < 0.5 * leniency) {
                            return { src: window.MOVE_ICON_URLS.best_move, title: "Best: The engine's top choice" };
                        }
                        return { src: window.MOVE_ICON_URLS.good_move, title: "Excellent: Nearly the top choice" };
                    }

                    function drawMoveAnnotationOnBoard() {
                        let overlay = $('#boardAnnOverlay');
                        if (overlay) overlay.remove();

                        if (currentMoveIdx === 0 || analysisPositions.length <= 1) return;
                        const currPos = analysisPositions[currentMoveIdx];
                        const prevPos = analysisPositions[currentMoveIdx - 1];
                        if (!currPos || !prevPos) return;

                        const turn = currentMoveIdx % 2 === 1 ? 'w' : 'b';
                        const moveNum = Math.ceil(currentMoveIdx / 2);
                        const prevPrevPos = currentMoveIdx >= 2 ? analysisPositions[currentMoveIdx - 2] : null;
                        const leniency = classificationLeniency(turn === 'w' ? analysisWhiteRating : analysisBlackRating);
                        const annIcon = getMoveAnnotationIcon(prevPos.score_cp, currPos.score_cp, turn, moveNum, prevPos, currPos, prevPrevPos, leniency);
                        if (!annIcon) return;

                        let toSquare = null;
                        if (currPos.move_san) {
                            try {
                                const tempChess = new window.Chess(prevPos.fen);
                                const m = tempChess.move(currPos.move_san);
                                if (m) toSquare = m.to;
                            } catch (e) { }
                        }

                        if (!toSquare) return;

                        const boardEl = $('#analysisBoard');
                        if (!boardEl) return;
                        boardEl.style.position = 'relative';

                        const orientation = (analysisBoard && analysisBoard.state && analysisBoard.state.orientation) || 'white';
                        const fileIdx = toSquare.charCodeAt(0) - 97; // a=0..h=7
                        const rankIdx = parseInt(toSquare[1]) - 1;   // 1=0..8=7

                        let xPct, yPct;
                        if (orientation === 'white') {
                            xPct = (fileIdx / 8) * 100;
                            yPct = ((7 - rankIdx) / 8) * 100;
                        } else {
                            xPct = ((7 - fileIdx) / 8) * 100;
                            yPct = (rankIdx / 8) * 100;
                        }

                        overlay = document.createElement('div');
                        overlay.id = 'boardAnnOverlay';
                        overlay.style.cssText = `position: absolute; left: ${xPct}%; top: ${yPct}%; width: 12.5%; height: 12.5%; pointer-events: none; z-index: 15; display: flex; align-items: flex-start; justify-content: flex-end;`;

                        overlay.innerHTML = `<img src="${annIcon.src}" title="${annIcon.title}" style="width: 22px; height: 22px; pointer-events: auto; transform: translate(30%, -30%); filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));" />`;
                        boardEl.appendChild(overlay);
                    }

                    // options.depth: search every position to this depth instead of the default
                    // node budget (the admin panel's custom-depth re-analysis).
                    // options.onProgress(done, total, phase): called for every position finished,
                    // so a batch can mirror this game's progress into its row in the review queue.
                    // Resolves to { ok, saved } — saved is whether the result was written back to
                    // the saved analysis (null when there was none to write to) —
                    // { ok: false, stopped: true } when the admin stopped the run, or undefined
                    // when the review didn't run. The Start button passes its click event; that
                    // has neither a depth nor a progress callback.
                    async function startPgnAnalysis(options = {}) {
                        // A batch owns the board and the engine pool for as long as it runs, so a
                        // review started by hand on top of one would fight it for both — and park
                        // on its pause gate. Only the batch's own calls (which pass onProgress)
                        // get through while it's running.
                        if (reanalysisRunning && !options?.onProgress) {
                            toast('Analisis batch sedang berjalan di panel admin.', 'error');
                            return;
                        }
                        const customDepth = Number.isInteger(options?.depth) ? options.depth : null;
                        const pgn = $('#pgnInput').value.trim();
                        if (!pgn) { toast('Masukkan PGN terlebih dahulu.', 'error'); return; }
                        // Fixed standard depth, independent of #liveDepthSelect — that input
                        // controls only the live/interactive position eval (depth 99), which
                        // would make a full-game batch analysis impractically slow at this depth.
                        // Server-side engine only, unless a custom depth was asked for: the browser
                        // pool searches to NODES_PER_LINE instead.
                        const depth = customDepth ?? 22;
                        const multipv = parseInt($('#liveMultiPvSelect')?.value) || 1;
                        // Per-position budget for the browser engine pool — lichess's fishnet
                        // model (a fixed node count, not a depth). 2M nodes matched depth 22's
                        // accuracy against native full-net Stockfish in benchmarks; MultiPV splits
                        // the search between lines, so each extra line gets its own 2M.
                        const NODES_PER_LINE = 2_000_000;
                        // The Great label needs the engine's second-best line, but only where the
                        // played move was the engine's best (see addSecondLines) — two lines for
                        // every position made the whole review ~2.4× slower.
                        const GREAT_LINES = 2;

                        // Parse PGN client-side
                        function parsePGNToMoves(pgnText) {
                            // Normalize line endings so both \r\n and \n are handled uniformly
                            pgnText = pgnText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

                            let headers = {};

                            // Extract ALL [Tag "Value"] pairs globally — works whether headers are on
                            // separate lines (standard PGN) or all on one line (VRChess compact format)
                            const headerRe = /\[(\w+)\s+"([^"]*)"\]/g;
                            let hm;
                            let lastHeaderEnd = 0;
                            while ((hm = headerRe.exec(pgnText)) !== null) {
                                headers[hm[1]] = hm[2];
                                lastHeaderEnd = hm.index + hm[0].length;
                            }

                            // Move text is everything after the last header tag.
                            // Handles both standard blank-line separator and compact single-line format.
                            let moveText = lastHeaderEnd > 0
                                ? pgnText.substring(lastHeaderEnd)
                                : pgnText;

                            moveText = moveText.replace(/\d+\.{1,3}/g, ' ');
                            moveText = moveText.replace(/1-0|0-1|1\/2-1\/2|\*/g, ' ');

                            const regex = /({[^}]*})|([a-zA-Z0-9\-+=#]+)/g;
                            let match;
                            let tokens = [];
                            while ((match = regex.exec(moveText)) !== null) {
                                tokens.push(match[0]);
                            }

                            let moves = [];
                            let currentMove = null;
                            for (let t of tokens) {
                                if (t.startsWith('{')) {
                                    if (currentMove) {
                                        const clkMatch = t.match(/\[%clk\s+([\d:]+)\]/);
                                        if (clkMatch) currentMove.clock = clkMatch[1];
                                    }
                                } else {
                                    let san = t.replace(/^0-0-0/, 'O-O-O').replace(/^0-0/, 'O-O');
                                    currentMove = { san: san, clock: null };
                                    moves.push(currentMove);
                                }
                            }
                            return { headers, moves };
                        }

                        const parsed = parsePGNToMoves(pgn);
                        analysisHeaders = parsed.headers;
                        const movesParsed = parsed.moves;
                        isCurrentGame960 = analysisHeaders.Variant && (analysisHeaders.Variant.toLowerCase() === 'fischerandom' || analysisHeaders.Variant.toLowerCase() === 'chess960');
                        updateAnalysisRatings();

                        function getInitialClock(tc) {
                            if (!tc || tc === '?' || tc === '-') return null;
                            let base = parseInt(tc.split('+')[0]);
                            if (isNaN(base)) return null;
                            let isMinutes = base <= 99;
                            let totalSeconds = isMinutes ? base * 60 : base;
                            let h = Math.floor(totalSeconds / 3600);
                            let m = Math.floor((totalSeconds % 3600) / 60);
                            let s = totalSeconds % 60;
                            if (h > 0) return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                            return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                        }
                        initialClock = getInitialClock(analysisHeaders.TimeControl);

                        // Uses chess.mjs (via the cm-pgn package) rather than the window.Chess
                        // CDN build used elsewhere: its sloppy-move parser natively resolves
                        // redundant disambiguation (e.g. "R4xd5" when only one rook can reach
                        // d5 at all), which some PGN sources emit and which the CDN chess.js
                        // build rejects outright. This only changes *interpretation* — the
                        // original SAN token from the PGN is still what gets stored/displayed
                        // (see `analysisMovesSan.push(san)` below), never a "corrected" one.
                        let chess = new window.CmChess({ chess960: isCurrentGame960 });
                        let startFen = analysisHeaders.FEN || 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

                        if (isCurrentGame960) {
                            // Replace X-FEN castling rights with pseudo-FEN KQkq so chess.mjs can load it
                            const parts = startFen.split(' ');
                            if (parts.length >= 3 && !parts[2].match(/^[KkQq-]+$/)) {
                                parts[2] = 'KQkq';
                                startFen = parts.join(' ');
                            }
                        }

                        if (!chess.load(startFen)) {
                            toast('Format FEN tidak valid.', 'error');
                            return;
                        }

                        const fenList = [chess.fen()];
                        analysisMovesSan = [];
                        const failedMoves = [];

                        for (let i = 0; i < movesParsed.length; i++) {
                            const move = movesParsed[i];
                            let san = move.san;
                            if (isCurrentGame960 && (san === 'O-O' || san === 'O-O-O')) {
                                // Manual 960 castling workaround. chess.mjs's own Chess960
                                // castling *generation* has gaps for some non-corner rook
                                // layouts (confirmed: king e1 / rook g1 with an empty board
                                // otherwise fails to generate O-O at all), so rather than rely
                                // on chess.move("O-O") finding a matching legal move, apply the
                                // king/rook relocation directly to the FEN — this is a replay
                                // of a game already known to be legal, not legality validation.
                                const fen = chess.fen();
                                const parts = fen.split(' ');
                                let board = parts[0], turn = parts[1], castling = parts[2];
                                const ep = '-'; let halfmove = 0; let fullmove = parseInt(parts[5], 10);
                                const isWhite = turn === 'w';
                                const rankIdx = isWhite ? 7 : 0;
                                let rows = board.split('/');
                                let rankStr = rows[rankIdx];
                                let expanded = '';
                                for (let j = 0; j < rankStr.length; j++) {
                                    if (/[1-8]/.test(rankStr[j])) expanded += ' '.repeat(parseInt(rankStr[j]));
                                    else expanded += rankStr[j];
                                }
                                const kingChar = isWhite ? 'K' : 'k';
                                const rookChar = isWhite ? 'R' : 'r';
                                const kIndex = expanded.indexOf(kingChar);
                                let rIndex = san === 'O-O' ? expanded.indexOf(rookChar, kIndex + 1) : expanded.lastIndexOf(rookChar, kIndex - 1);
                                let arr = expanded.split('');
                                arr[kIndex] = ' '; arr[rIndex] = ' ';
                                if (san === 'O-O') { arr[6] = kingChar; arr[5] = rookChar; }
                                else { arr[2] = kingChar; arr[3] = rookChar; }
                                expanded = arr.join('');
                                let compressed = '', spaces = 0;
                                for (let j = 0; j < 8; j++) {
                                    if (expanded[j] === ' ') spaces++;
                                    else { if (spaces > 0) compressed += spaces; spaces = 0; compressed += expanded[j]; }
                                }
                                if (spaces > 0) compressed += spaces;
                                rows[rankIdx] = compressed;
                                board = rows.join('/');
                                if (isWhite) castling = castling.replace('K', '').replace('Q', '');
                                else { castling = castling.replace('k', '').replace('q', ''); fullmove++; }
                                if (castling === '') castling = '-';
                                chess.load(`${board} ${turn === 'w' ? 'b' : 'w'} ${castling} ${ep} ${halfmove} ${fullmove}`);
                            } else {
                                const moveResult = chess.move(san, { sloppy: true });
                                if (!moveResult) {
                                    // A move the parser genuinely can't interpret at all (not just
                                    // redundant disambiguation — chess.mjs's sloppy parser already
                                    // resolves that natively). Record it and move on rather than
                                    // silently re-pushing the unchanged pre-move position, which
                                    // would corrupt this and every subsequent saved position with
                                    // no indication anything had gone wrong.
                                    failedMoves.push({ ply: i + 1, san });
                                    console.error(`Move ${i + 1} ("${san}") could not be applied — position from here on may be incorrect.`, chess.fen());
                                }
                            }
                            fenList.push(chess.fen());
                            analysisMovesSan.push(san);
                        }

                        if (failedMoves.length > 0) {
                            const list = failedMoves.map(m => `${m.ply}. ${m.san}`).join(', ');
                            toast(`Peringatan: ${failedMoves.length} langkah gagal diproses (${list}) — posisi setelahnya mungkin tidak akurat.`, 'error');
                        }

                        const total = fenList.length;

                        // Walks the game from move 1 while each played move is in the Lichess masters
                        // database (/api/opening-book), stopping at the first that isn't: like
                        // chess.com, a game leaves book once rather than drifting back in by
                        // transposition. complete:false when the lookup couldn't finish (no Lichess
                        // token on the server, Lichess unreachable) — see where its result is applied.
                        const BOOK_MIN_GAMES = 2;  // a lone master game can be a one-off, not theory
                        const BOOK_MAX_PLIES = 40;
                        async function lookupOpeningBook() {
                            if (isCurrentGame960) return { plies: 0, complete: false };
                            const bare = san => String(san || '').replace(/[+#!?]+$/, '');
                            let plies = 0;
                            for (let i = 1; i < total && i <= BOOK_MAX_PLIES; i++) {
                                // Named openings (the bundled list) count too: common amateur lines
                                // like the Scholar's Mate attack have few or no master games.
                                if (openingAt(fenList[i])) {
                                    plies = i;
                                    continue;
                                }
                                try {
                                    const res = await fetch(`/api/opening-book?fen=${encodeURIComponent(fenList[i - 1])}`);
                                    const data = await res.json();
                                    if (!res.ok || !data.success || !data.available) return { plies, complete: false };
                                    const entry = data.moves.find(m => bare(m.san) === bare(analysisMovesSan[i - 1]));
                                    if (!entry || entry.games < BOOK_MIN_GAMES) break;
                                    plies = i;
                                } catch (e) {
                                    return { plies, complete: false };
                                }
                            }
                            return { plies, complete: true };
                        }

                        // A live search still running on the previous position would only take CPU
                        // from the review; gotoMove(0) afterwards decides whether it resumes.
                        if (liveAnalysisAbort) liveAnalysisAbort.abort();
                        updateEngineStreamBadge(false);

                        // Show progress. Every update goes through setAnalysisProgress so the
                        // admin panel's per-game bar (options.onProgress) can't drift out of sync
                        // with the one on this tab.
                        $('#analysisProgress').style.display = 'block';
                        let progressPhase = 'posisi';
                        function setAnalysisProgress(done, outOf, phase) {
                            if (phase) progressPhase = phase;
                            $('#analysisCurrent').textContent = done;
                            $('#analysisTotal').textContent = progressPhase === 'posisi' ? outOf : `${outOf} (${progressPhase})`;
                            $('#analysisProgressBar').style.width = (outOf ? Math.round((done / outOf) * 100) : 0) + '%';
                            options.onProgress?.(done, outOf, progressPhase);
                        }
                        setAnalysisProgress(0, total, 'posisi');

                        // Analyzes one FEN on one pool engine, shaped like a /api/engine/analyze position entry.
                        async function analyzeFenClient(session, fen, moveIndex, lines = multipv) {
                            const limit = customDepth ? { depth: customDepth } : { nodes: NODES_PER_LINE * lines };
                            const result = await session.analyze(fen, { ...limit, multipv: lines, chess960: isCurrentGame960 });

                            let scoreDisplay = null;
                            let scoreCp = null;
                            const turn = fen.split(' ')[1] || 'w';
                            if (result.score_type === 'cp') {
                                scoreCp = turn === 'b' ? -result.score : result.score;
                                scoreDisplay = scoreCp / 100;
                            } else if (result.score_type === 'mate') {
                                scoreCp = result.score > 0 ? 9999 : -9999;
                                if (turn === 'b') scoreCp = -scoreCp;
                                scoreDisplay = "M" + Math.abs(result.score);
                            }

                            return {
                                fen,
                                move_index: moveIndex,
                                score: scoreDisplay,
                                score_cp: scoreCp,
                                score_type: result.score_type,
                                bestmove: result.bestmove,
                                pv: (result.pv || []).slice(0, 6),
                                depth: result.depth,
                                multipv: result.multipv || {},
                            };
                        }

                        // One Lichess cloud-eval lookup, shaped like analyzeFenClient()'s result.
                        // null means "not from Lichess" for any reason — not stored, rate-limited,
                        // timed out, offline — and the caller moves on to the engines.
                        async function lookupLichessCloudEval(fen, moveIndex) {
                            const controller = new AbortController();
                            const timer = setTimeout(() => controller.abort(), 3000);
                            try {
                                const res = await fetch(
                                    `https://lichess.org/api/cloud-eval?fen=${encodeURIComponent(fen)}&multiPv=${Math.max(GREAT_LINES, multipv)}`,
                                    { signal: controller.signal, credentials: 'omit' },
                                );
                                if (res.status === 429) {
                                    lichessPausedUntil = Date.now() + 60000;
                                    return null;
                                }
                                // Error responses (429 included) are HTML pages, so check before parsing.
                                if (!res.ok) return null;
                                return lichessEvalToPosition(fen, moveIndex, await res.json());
                            } catch (_) {
                                return null;
                            } finally {
                                clearTimeout(timer);
                            }
                        }

                        // Work-shared batch: browser and server engines both pull positions off the
                        // same queue and analyze concurrently — a healthy pair finishes roughly
                        // twice as fast as either alone. The browser is prioritized: it always
                        // claims one position at a time (never idle waiting on a big reservation),
                        // while the server claims positions in a chunk sized adaptively from how
                        // its measured per-position latency compares to the browser's — a lagging
                        // cloud automatically gets handed a smaller slice next time instead of
                        // tying up a large batch of positions the fast local engine could otherwise
                        // have covered. A position the client engine fails on is simply handed to
                        // the server rather than disabling the client for the rest of the batch —
                        // the very next position it claims is tried fresh.
                        analysisPositions = new Array(total);
                        const MAX_SERVER_CHUNK = 20;
                        const MIN_SERVER_CHUNK = 2;
                        let serverChunkSize = 8; // starting guess; adapts after the first chunk lands
                        let localAvgMs = null; // per position across the whole browser pool
                        let serverAvgMs = null;
                        let clientPoolSize = 1;
                        let nextClaim = 0;
                        let completedCount = 0;
                        const serverBacklog = [];
                        let batchFailed = false;

                        const emaUpdate = (prev, sample) => prev === null ? sample : prev * 0.7 + sample * 0.3;

                        function buildPosEntry(pos, globalIdx) {
                            return {
                                fen: pos.fen,
                                move_san: globalIdx === 0 ? null : analysisMovesSan[globalIdx - 1],
                                clock: globalIdx === 0 ? null : (movesParsed[globalIdx - 1] ? movesParsed[globalIdx - 1].clock : null),
                                score: pos.score,
                                score_cp: pos.score_cp,
                                score_type: pos.score_type,
                                bestmove: pos.bestmove,
                                pv: pos.pv || [],
                                multipv: pos.multipv || [],
                                depth: pos.depth ?? null,
                            };
                        }

                        function recordProgress() {
                            setAnalysisProgress(++completedCount, total);
                        }

                        // Opening phase: take Lichess's stored evaluations one position at a time
                        // from move 0, and stop at the first one it doesn't have. Once a game
                        // leaves known theory, later positions are almost never stored, so
                        // carrying on would only spend Lichess's rate limit. Advancing nextClaim
                        // is the handoff — the engine workers claim from wherever this stopped.
                        // Chess960 is skipped: its positions are practically never stored.
                        async function runLichessOpeningPhase() {
                            if (isCurrentGame960 || Date.now() < lichessPausedUntil) return 0;
                            let hits = 0;
                            while (nextClaim < total) {
                                for (let gate; (gate = whenReviewResumed());) await gate;
                                if (reviewStopRequested) break;
                                const pos = await lookupLichessCloudEval(fenList[nextClaim], nextClaim);
                                if (!pos) break;
                                analysisPositions[nextClaim] = buildPosEntry(pos, nextClaim);
                                nextClaim++;
                                hits++;
                                recordProgress();
                            }
                            return hits;
                        }

                        // One per pool engine. Progress is only recorded on success: a failed
                        // position is counted when the server finishes it, and counting it here as
                        // well would let the "browser only" backstop see completedCount >= total and
                        // quit with that position still unanalyzed.
                        async function clientBatchWorker(session) {
                            while (!batchFailed && !reviewStopRequested && !session.wedged) {
                                // Claim only once the run is live: a pause parks the worker here,
                                // before it takes a position, so nothing is left half-searched and
                                // resuming carries on from the position the queue stopped at.
                                for (let gate; (gate = whenReviewResumed());) await gate;
                                if (batchFailed || reviewStopRequested) return;
                                const i = nextClaim < total ? nextClaim++ : null;
                                if (i === null) return;
                                const startedAt = performance.now();
                                try {
                                    const pos = await analyzeFenClient(session, fenList[i], i);
                                    analysisPositions[i] = buildPosEntry(pos, i);
                                    localAvgMs = emaUpdate(localAvgMs, (performance.now() - startedAt) / clientPoolSize);
                                    recordProgress();
                                } catch (err) {
                                    console.warn(`Client engine failed on position ${i}, handing it to the server:`, err);
                                    serverBacklog.push(i);
                                }
                            }
                        }

                        // Re-searches, with GREAT_LINES lines, each position where the played move
                        // was the engine's best and only one line was computed, so the classifier
                        // can tell whether that move was the only good one (Great). The two-line
                        // result replaces the one-line one: a stronger search, internally consistent.
                        // A position whose re-search fails keeps its one-line result.
                        async function addSecondLines(pool) {
                            const wanted = [];
                            for (let i = 1; i < total; i++) {
                                const prev = analysisPositions[i - 1], curr = analysisPositions[i];
                                if (!prev || !curr || prev.multipv?.['2'] || prev.multipv?.[2]) continue;
                                if (prev.bestmove && playedMoveUci(prev, curr) === prev.bestmove) wanted.push(i - 1);
                            }
                            if (!pool.length || !wanted.length) return;

                            let next = 0, done = 0;
                            setAnalysisProgress(0, wanted.length, 'jalur kedua');
                            await Promise.all(pool.map(async (session) => {
                                while (!session.wedged && next < wanted.length) {
                                    for (let gate; (gate = whenReviewResumed());) await gate;
                                    if (reviewStopRequested) return;
                                    const idx = wanted[next++];
                                    try {
                                        analysisPositions[idx] = buildPosEntry(await analyzeFenClient(session, fenList[idx], idx, GREAT_LINES), idx);
                                    } catch (e) {
                                        console.warn(`Second engine line failed for position ${idx}:`, e);
                                    }
                                    setAnalysisProgress(++done, wanted.length);
                                }
                            }));
                        }

                        async function runClientPool(pool) {
                            clientPoolSize = Math.max(1, pool.length);
                            await Promise.all(pool.map(clientBatchWorker));
                            if (reviewStopRequested) return;
                            // No engine, or every engine wedged before the queue ran dry: hand the
                            // rest to the server rather than leave them unclaimed in "browser only" mode.
                            while (nextClaim < total) serverBacklog.push(nextClaim++);
                        }

                        async function serverBatchWorker() {
                            if (enginePreference === 'client') {
                                // Even in "browser only" mode, the server still mops up positions the
                                // client engine couldn't handle — it just never competes for fresh work.
                                while (!batchFailed && !reviewStopRequested) {
                                    for (let gate; (gate = whenReviewResumed());) await gate;
                                    if (reviewStopRequested) return;
                                    if (completedCount >= total) return;
                                    if (serverBacklog.length === 0) {
                                        await new Promise((r) => setTimeout(r, 60));
                                        continue;
                                    }
                                    await runServerBatchChunk(serverBacklog.splice(0, MAX_SERVER_CHUNK));
                                }
                                return;
                            }

                            while (!batchFailed && !reviewStopRequested) {
                                for (let gate; (gate = whenReviewResumed());) await gate;
                                if (reviewStopRequested) return;
                                const batchIdxs = [];
                                while (batchIdxs.length < serverChunkSize) {
                                    if (serverBacklog.length) { batchIdxs.push(serverBacklog.shift()); continue; }
                                    if (nextClaim < total) { batchIdxs.push(nextClaim++); continue; }
                                    break;
                                }
                                if (batchIdxs.length === 0) {
                                    if (completedCount >= total) return;
                                    await new Promise((r) => setTimeout(r, 60)); // wait for a possible client-engine handoff
                                    continue;
                                }
                                await runServerBatchChunk(batchIdxs);
                            }
                        }

                        async function runServerBatchChunk(batchIdxs) {
                            const startedAt = performance.now();
                            try {
                                const res = await fetch('/api/engine/analyze/batch', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ fens: batchIdxs.map((i) => fenList[i]), depth, multipv: serverLines, chess960: isCurrentGame960 })
                                });
                                const data = await res.json();
                                if (!res.ok || !data.success) throw new Error(data.error || 'Server error');
                                // The server always covers at least one position, so an empty batch
                                // means something is wrong. Fail loudly rather than re-queueing the
                                // same indices forever against a server that isn't consuming them.
                                if (!data.positions?.length) throw new Error('Server returned no positions');
                                const returnedIdxs = new Set();
                                for (const pos of data.positions) {
                                    const globalIdx = batchIdxs[pos.move_index];
                                    analysisPositions[globalIdx] = buildPosEntry(pos, globalIdx);
                                    returnedIdxs.add(pos.move_index);
                                    recordProgress();
                                }

                                // The server stops a batch early when finishing it would outrun the
                                // origin's request limit, so a short response is expected rather than
                                // a failure. Put whatever it didn't reach back at the head of the
                                // queue — dropping it would strand those positions and leave the
                                // progress bar short of 100% forever.
                                const leftover = batchIdxs.filter((_, i) => !returnedIdxs.has(i));
                                if (leftover.length) serverBacklog.unshift(...leftover);

                                // Adapt: shrink the cloud's next slice when it's measurably slower per
                                // position than the browser (lagging), grow it back when it's keeping
                                // pace — so a lagging server never locks up a large chunk of positions
                                // that the browser engine could otherwise have gotten to.
                                // Divide by what came back, not what was asked for — a truncated
                                // batch spent its whole wall-clock on fewer positions, and charging
                                // the time to the full request would understate the real cost and
                                // size the next chunk too big.
                                const timedCount = data.positions.length || batchIdxs.length;
                                serverAvgMs = emaUpdate(serverAvgMs, (performance.now() - startedAt) / timedCount);
                                if (localAvgMs !== null && serverAvgMs !== null) {
                                    if (serverAvgMs > localAvgMs * 1.5) {
                                        serverChunkSize = Math.max(MIN_SERVER_CHUNK, serverChunkSize - 2);
                                    } else if (serverAvgMs < localAvgMs * 1.1) {
                                        serverChunkSize = Math.min(MAX_SERVER_CHUNK, serverChunkSize + 2);
                                    }
                                }
                            } catch (err) {
                                batchFailed = true;
                                toast('Analisis gagal: ' + err.message, 'error');
                            }
                        }

                        // Resolved up front (not just inside clientBatchWorker) so both workers see
                        // the same, already-corrected enginePreference before they start — otherwise
                        // they could race: serverBatchWorker reading a still-stale 'client' preference
                        // and sitting in backstop-only mode while clientBatchWorker's own probe is the
                        // one that would have corrected it, which never arrives if the browser engine
                        // turns out to be unavailable.
                        // Opening theory for the Book label, looked up alongside the engines.
                        const bookReady = lookupOpeningBook();

                        await probeClientEngine();

                        // Boot the browser engine pool while the Lichess lookups are in flight.
                        const poolReady = enginePreference === 'server' ? Promise.resolve([]) : window.VRChessEngine.pool();
                        // A custom depth means every position at exactly that depth, so Lichess's
                        // (much deeper) stored evaluations are left out of that review.
                        const lichessHits = customDepth ? 0 : await runLichessOpeningPhase();
                        const pool = await poolReady;
                        // Without a browser pool (server-only mode) nothing can add the second
                        // line afterwards, so the server computes two lines up front.
                        const serverLines = pool.length ? multipv : Math.max(GREAT_LINES, multipv);

                        if ($('#analysisEngineLabel')) {
                            const browser = `${ICONS.monitor} Browser ×${pool.length}`;
                            const engines = enginePreference === 'both' ? `${browser} + ${ICONS.cloud} Server`
                                : enginePreference === 'client' ? browser : `${ICONS.cloud} Server`;
                            const lichess = lichessHits ? `${ICONS.book} Lichess ×${lichessHits} + ` : '';
                            $('#analysisEngineLabel').innerHTML = `(${lichess}${engines})`;
                        }

                        await Promise.all([runClientPool(pool), serverBatchWorker()]);
                        if (!batchFailed && !reviewStopRequested) await addSecondLines(pool);

                        $('#btnStartPgnAnalysis').disabled = false;
                        $('#analysisProgress').style.display = 'none';

                        // A stopped run leaves this game only partly searched, so it returns
                        // before the save below: the stored analysis keeps the evaluations it
                        // already had rather than being overwritten with a half-reviewed game.
                        if (reviewStopRequested) return { ok: false, stopped: true };
                        if (batchFailed) return;

                        // Moves 1..plies are book. Only a lookup that ran to the end of the
                        // opening may mark the rest as not-book; after a failed or partial one
                        // those stay unset, so the classifier falls back to the bundled list.
                        const book = await bookReady;
                        analysisPositions.forEach((pos, i) => {
                            if (!pos || i === 0) return;
                            if (i <= book.plies) pos.book = true;
                            else if (book.complete) pos.book = false;
                        });

                        analysisPositions = analysisPositions.filter(Boolean); // defensive — should already be fully populated
                        mainPositions = [...analysisPositions];

                        // Show main layout
                        $('#analysisPgnImportCard').style.display = 'none';
                        $('#analysisMainLayout').style.display = 'block';

                        // Populate game info
                        $('#infoWhite').textContent = analysisHeaders.White || '?';
                        $('#infoBlack').textContent = analysisHeaders.Black || '?';
                        $('#infoEvent').textContent = analysisHeaders.Event && analysisHeaders.Event !== '?' ? analysisHeaders.Event : '';
                        $('#infoDate').textContent = analysisHeaders.Date || '';
                        $('#infoResult').textContent = analysisHeaders.Result || '';

                        renderMoveList();
                        renderEvalChart();

                        // Deferred: the board container was just switched from display:none to
                        // block, so chessground doesn't have real pixel dimensions on this tick yet
                        // — redrawAll() (to pick up the new size) and gotoMove(0) (which draws the
                        // best-move arrow) both need to run after that first layout/paint, or the
                        // arrow's square-to-pixel math produces NaN SVG coordinates.
                        setTimeout(() => {
                            if (analysisBoard) analysisBoard.redrawAll();
                            gotoMove(0);
                        }, 50);

                        // Save back to the saved analysis this review belongs to (a shared link that
                        // needed re-analysis, or an admin re-analysis). Awaited so a batch can tell
                        // whether each game was actually stored.
                        let saved = null;
                        if (currentAnalysisId && mainPositions.length > 1) {
                            try {
                                const res = await fetch(`/api/analyses/${encodeURIComponent(currentAnalysisId)}`, {
                                    method: 'PATCH',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ analysis: mainPositions })
                                });
                                saved = res.ok && (await res.json()).success === true;
                            } catch (e) {
                                console.error('Auto-save failed:', e);
                                saved = false;
                            }
                        }
                        return { ok: true, saved };
                    }

                    // ── Admin: (re)analyze saved games ──
                    // Each game runs through the normal Game Review above, one at a time, on the
                    // Analysis tab so its progress shows, and is saved back to its saved analysis
                    // (replacing existing evaluations needs the admin session this runs under).

                    // '' → default node budget; otherwise a whole depth within what the server
                    // engine also accepts, so both engines honor it.
                    function parseReanalysisDepth(raw) {
                        const text = String(raw ?? '').trim();
                        if (!text) return { depth: null };
                        const depth = Number(text);
                        if (!Number.isInteger(depth) || depth < 8 || depth > 26) {
                            return { error: 'Depth harus angka bulat 8–26, atau kosong untuk default.' };
                        }
                        return { depth };
                    }

                    async function reviewSavedAnalyses(ids, depth) {
                        if (reanalysisRunning) { toast('Analisis lain masih berjalan.', 'error'); return; }
                        reanalysisRunning = true;
                        reviewPaused = false;
                        reviewStopRequested = false;
                        reviewBatchIds = [...ids];
                        reviewProgress.clear();
                        for (const id of ids) reviewProgress.set(id, { state: 'queued', done: 0, total: 0, phase: 'posisi' });
                        setReviewButtonsEnabled(false);
                        renderReviewQueue();

                        const status = $('#reanalysisStatus');
                        const failed = [];
                        // Where the run stopped, so the games after it are reported as cancelled
                        // rather than failed; -1 means it ran to the end.
                        let stoppedAt = -1;
                        try {
                            // Watching the board is opt-in now: the per-game bars are in this
                            // panel, so a run no longer has to drag the admin off it.
                            if ($('#reviewFollowBoard')?.checked) $('#tabBtnAnalysis').click();
                            for (const [n, id] of ids.entries()) {
                                // Pause holds between games too, so a run can be parked without
                                // leaving a game half-reviewed.
                                for (let gate; (gate = whenReviewResumed());) await gate;
                                if (reviewStopRequested) { stoppedAt = n; break; }

                                reviewActiveId = id;
                                Object.assign(reviewProgress.get(id), { state: 'running', done: 0, total: 0, phase: 'posisi' });
                                if (status) status.textContent = `Analisis ${n + 1}/${ids.length}${depth ? ` (depth ${depth})` : ''}…`;
                                renderReviewQueue();

                                let result;
                                try {
                                    const res = await apiCall(`/api/analyses/${encodeURIComponent(id)}`);
                                    currentAnalysisId = id;
                                    $('#pgnInput').value = res.data.pgn;
                                    result = await startPgnAnalysis({
                                        ...(depth ? { depth } : {}),
                                        onProgress: (done, total, phase) => updateReviewRowProgress(id, done, total, phase),
                                    });
                                } catch (e) {
                                    console.error(`Re-analysis of ${id} failed:`, e);
                                }
                                reviewActiveId = null;

                                const prog = reviewProgress.get(id);
                                if (result?.stopped) { prog.state = 'skipped'; stoppedAt = n; break; }
                                if (!result?.ok || !result.saved) { prog.state = 'failed'; failed.push(id); }
                                else { prog.state = 'done'; reviewSelection.delete(id); fetchAnalyses(true); } // the panel shows this game's new depth
                                renderReviewQueue();
                            }
                        } finally {
                            reviewActiveId = null;
                            reanalysisRunning = false;
                            reviewStopRequested = false;
                            reviewPaused = false;
                            reviewPauseWaiters.splice(0).forEach(resolve => resolve());
                            if (stoppedAt >= 0) {
                                for (const id of ids.slice(stoppedAt)) {
                                    const prog = reviewProgress.get(id);
                                    if (prog && prog.state !== 'done' && prog.state !== 'failed') prog.state = 'skipped';
                                }
                            }
                            setReviewButtonsEnabled(true);
                            renderReviewQueue();
                        }

                        const attempted = stoppedAt >= 0 ? stoppedAt : ids.length;
                        const saved = attempted - failed.length;
                        const message = (stoppedAt >= 0
                            ? `Dihentikan: ${saved}/${ids.length} tersimpan, ${ids.length - attempted} dibatalkan`
                            : `Selesai: ${saved}/${ids.length} tersimpan`)
                            + (failed.length ? `; gagal: ${failed.join(', ')}` : '.');
                        if (status) status.textContent = message;
                        toast(message, failed.length ? 'error' : 'success');
                        fetchAnalyses(true);
                    }

                    $('#btnReviewPause')?.addEventListener('click', () => {
                        if (!reanalysisRunning || reviewStopRequested) return;
                        setReviewPaused(!reviewPaused);
                        toast(reviewPaused
                            ? 'Dijeda. Posisi yang sedang dihitung diselesaikan dulu.'
                            : 'Dilanjutkan.', 'info');
                    });

                    $('#btnReviewStop')?.addEventListener('click', () => {
                        if (!reanalysisRunning || reviewStopRequested) return;
                        if (!confirm('Hentikan sisa analisis? Game yang sedang berjalan dibatalkan dan tidak disimpan — game yang sudah selesai tetap tersimpan.')) return;
                        reviewStopRequested = true;
                        setReviewPaused(false); // release the workers so they can see the stop and exit
                        renderReviewBatchBar();
                    });

                    // Panel buttons: each runs its set of games at the depth field's value.
                    function analyzeBatch(ids, emptyMessage, what) {
                        const parsed = parseReanalysisDepth($('#reanalysisDepth')?.value);
                        if (parsed.error) { toast(parsed.error, 'error'); return; }
                        if (!ids.length) { toast(emptyMessage, 'info'); return; }
                        if (!confirm(`Analisis ${ids.length} ${what} di browser ini${parsed.depth ? ` (depth ${parsed.depth})` : ''}? Halaman ini harus tetap terbuka sampai selesai.`)) return;
                        reviewSavedAnalyses(ids, parsed.depth);
                    }
                    $('#btnAnalyzeUnanalyzed')?.addEventListener('click', () => analyzeBatch(
                        allAnalyses.filter(an => an.analyzed === false).map(an => an.id),
                        'Semua game tersimpan sudah dianalisis.', 'game yang belum dianalisis'));
                    $('#btnAnalyzeShallow')?.addEventListener('click', () => {
                        const target = reviewTargetDepth();
                        if (!target) { toast('Isi depth (8–26) dulu: game di bawah depth itu yang dianalisis ulang.', 'error'); return; }
                        analyzeBatch(allAnalyses.filter(an => isShallowReview(an, target)).map(an => an.id),
                            `Tidak ada game yang lebih dangkal dari depth ${target}.`, `game yang lebih dangkal dari depth ${target}`);
                    });
                    $('#btnAnalyzeSelected')?.addEventListener('click', () => analyzeBatch(
                        [...reviewSelection], 'Belum ada game yang dipilih.', 'game yang dipilih'));
                    $('#reanalysisDepth')?.addEventListener('input', renderReviewQueue);
                    $('#reviewQueueFilter')?.addEventListener('change', renderReviewQueue);
                    $('#reviewQueue')?.addEventListener('change', (e) => {
                        const box = e.target;
                        if (box.dataset.reviewAll !== undefined) {
                            const visible = JSON.parse($('#reviewQueue').dataset.visibleIds || '[]');
                            visible.forEach(id => box.checked ? reviewSelection.add(id) : reviewSelection.delete(id));
                        } else if (box.dataset.reviewId) {
                            box.checked ? reviewSelection.add(box.dataset.reviewId) : reviewSelection.delete(box.dataset.reviewId);
                        } else {
                            return;
                        }
                        renderReviewQueue();
                    });

                    // Per-game "Analisis Ulang" button in Riwayat Analisis (admin only), with its
                    // own depth prompt, prefilled from the admin panel's depth field.
                    window.appReanalyze = (id) => {
                        const raw = prompt('Depth untuk analisis ulang (8–26, kosongkan untuk default):', $('#reanalysisDepth')?.value || '');
                        if (raw === null) return;
                        const parsed = parseReanalysisDepth(raw);
                        if (parsed.error) { toast(parsed.error, 'error'); return; }
                        reviewSavedAnalyses([id], parsed.depth);
                    };

                    function gotoMove(idx) {
                        if (analysisPositions.length === 0) return;
                        idx = Math.max(0, Math.min(idx, analysisPositions.length - 1));

                        // The active line is always "path to here, then the main line onward", so
                        // Next follows the main line and Back out of a variation returns to it.
                        recordActiveLine();
                        analysisPositions = analysisPositions.slice(0, idx + 1);
                        for (let kids; (kids = childrenOf(analysisPositions[analysisPositions.length - 1])).length;) {
                            analysisPositions.push(kids[0]);
                        }

                        currentMoveIdx = idx;
                        const pos = analysisPositions[idx];

                        analysisChess.load(pos.fen);

                        // Update player clocks
                        let wClock = null, bClock = null;
                        for (let i = idx; i >= 1; i--) {
                            if (!wClock && i % 2 === 1 && analysisPositions[i].clock) wClock = analysisPositions[i].clock;
                            if (!bClock && i % 2 === 0 && analysisPositions[i].clock) bClock = analysisPositions[i].clock;
                            if (wClock && bClock) break;
                        }
                        if (!wClock) wClock = initialClock;
                        if (!bClock) bClock = initialClock;

                        if (!wClock) {
                            for (let i = 1; i < analysisPositions.length; i += 2) {
                                if (analysisPositions[i].clock) { wClock = analysisPositions[i].clock; break; }
                            }
                        }
                        if (!bClock) {
                            for (let i = 2; i < analysisPositions.length; i += 2) {
                                if (analysisPositions[i].clock) { bClock = analysisPositions[i].clock; break; }
                            }
                        }
                        const cw = $('#clockWhite');
                        const cb = $('#clockBlack');
                        if (cw && cb) {
                            if (wClock) { cw.textContent = wClock; cw.style.display = 'inline-block'; } else cw.style.display = 'none';
                            if (bClock) { cb.textContent = bClock; cb.style.display = 'inline-block'; } else cb.style.display = 'none';
                            const turn = idx % 2 === 0 ? 'w' : 'b';
                            cw.style.borderColor = turn === 'w' ? '#aaa' : '#444';
                            cw.style.color = turn === 'w' ? '#fff' : '#888';
                            cb.style.borderColor = turn === 'b' ? '#aaa' : '#444';
                            cb.style.color = turn === 'b' ? '#fff' : '#888';
                        }

                        analysisBoard.set({
                            fen: pos.fen,
                            turnColor: toColor(analysisChess),
                            movable: {
                                color: 'both',
                                free: false,
                                dests: getLegalMoves(),
                                events: {
                                    after: handleAnalysisMove
                                }
                            }
                        });

                        // Move label
                        if (idx === 0) {
                            $('#analysisMoveLabel').textContent = 'Start';
                        } else {
                            const moveNum = Math.ceil(idx / 2);
                            const side = idx % 2 === 1 ? 'W' : 'B';
                            $('#analysisMoveLabel').textContent = `${moveNum}. ${side}: ${pos.move_san || '?'}`;
                        }

                        // Eval box
                        if (pos.score !== null && pos.score !== undefined) {
                            const sc = pos.score;
                            const isNum = typeof sc === 'number';
                            let display = isNum ? (sc >= 0 ? `+${sc.toFixed(2)}` : sc.toFixed(2)) : String(sc);
                            $('#posEvalScore').textContent = display;
                            $('#posEvalScore').style.color = isNum
                                ? (sc > 0.2 ? '#fff' : sc < -0.2 ? 'var(--accent-red)' : '#fff')
                                : '#fff';
                            updateEvalBar(pos.score_cp);
                        }
                        if ($('#posEvalDepth')) $('#posEvalDepth').textContent = pos.depth ?? '—';
                        $('#posEvalBest').textContent = pos.bestmove || '—';
                        const pvText = pos.pv && pos.pv.length ? pos.pv.join(' ') : '—';
                        $('#posEvalPv').textContent = pvText;

                        renderMoveList();
                        renderEvalChart();
                        drawBestMoveArrow();
                        drawMoveAnnotationOnBoard();
                        renderGameStats();
                        updateOpeningUI(idx);

                        // Game Review already scored this position → leave it be; no eval yet, or one
                        // the live engine itself produced (a variation still being explored) → run it.
                        const needsLive = pos.score === null || pos.score === undefined || !!pos.live;
                        if (!liveEngineByUser && isLiveEngineEnabled !== needsLive) setLiveEngineEnabled(needsLive);

                        if (isLiveEngineEnabled && analysisPositions[idx]) {
                            analyzeLivePosition(idx, pos.fen);
                        } else {
                            if (liveAnalysisAbort) liveAnalysisAbort.abort();
                            updateEngineStreamBadge(false);
                        }
                    }

                    function updateOpeningUI(idx) {
                        if (idx === 0) {
                            $('#openingInfo').style.display = 'flex';
                            $('#openingEco').textContent = 'Starting';
                            $('#openingName').textContent = 'Initial Position';
                            return;
                        }

                        let longestMatch = null;
                        for (let i = 1; i <= idx; i++) {
                            const entry = analysisPositions[i]?.fen && openingAt(analysisPositions[i].fen);
                            if (entry) longestMatch = entry;
                        }

                        if (longestMatch) {
                            $('#openingInfo').style.display = 'flex';
                            $('#openingEco').textContent = longestMatch.eco;
                            $('#openingName').textContent = longestMatch.name;
                        } else {
                            $('#openingInfo').style.display = 'none';
                        }
                    }

                    function renderGameStats() {
                        const statsW = {};
                        const statsB = {};
                        const leniencyW = classificationLeniency(analysisWhiteRating);
                        const leniencyB = classificationLeniency(analysisBlackRating);

                        for (let i = 1; i < analysisPositions.length; i++) {
                            const turn = i % 2 === 1 ? 'w' : 'b';
                            const moveNum = Math.ceil(i / 2);
                            const currPos = analysisPositions[i];
                            const prevPos = analysisPositions[i - 1];
                            const prevPrevPos = i >= 2 ? analysisPositions[i - 2] : null;

                            const ann = getMoveAnnotationIcon(prevPos.score_cp, currPos.score_cp, turn, moveNum, prevPos, currPos, prevPrevPos, turn === 'w' ? leniencyW : leniencyB);
                            if (ann) {
                                const target = turn === 'w' ? statsW : statsB;
                                if (!target[ann.src]) {
                                    target[ann.src] = { count: 0, title: ann.title };
                                }
                                target[ann.src].count++;
                            }
                        }

                        const order = [
                            window.MOVE_ICON_URLS.brilliant,
                            window.MOVE_ICON_URLS.the_only_move,
                            window.MOVE_ICON_URLS.best_move,
                            window.MOVE_ICON_URLS.good_move,
                            window.MOVE_ICON_URLS.theoritical_move,
                            window.MOVE_ICON_URLS.tactical_move,
                            window.MOVE_ICON_URLS.strange_move,
                            window.MOVE_ICON_URLS.missed_tactic,
                            window.MOVE_ICON_URLS.blunder
                        ];

                        function buildHtml(stats) {
                            let html = '';
                            for (const src of order) {
                                if (stats[src] && stats[src].count > 0) {
                                    html += `<div style="display:flex; align-items:center; gap:2px; background:rgba(0,0,0,0.4); padding:2px 5px; border-radius:4px;" title="${stats[src].title}">
                                                <img src="${src}" style="width:14px; height:14px; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5));" />
                                                <span style="color:#eee; font-size:0.75rem; font-weight:600;">${stats[src].count}</span>
                                             </div>`;
                                }
                            }
                            return html;
                        }

                        const sw = $('#statsWhite');
                        const sb = $('#statsBlack');
                        if (sw) sw.innerHTML = buildHtml(statsW);
                        if (sb) sb.innerHTML = buildHtml(statsB);

                        const accW = $('#accWhite');
                        const accB = $('#accBlack');
                        const accWVal = computeGameAccuracy('w');
                        const accBVal = computeGameAccuracy('b');
                        if (accW) accW.innerText = accWVal + '%';
                        if (accB) accB.innerText = accBVal + '%';

                        const ratingW = estimatePerformanceRating(parseFloat(accWVal));
                        const ratingB = estimatePerformanceRating(parseFloat(accBVal));
                        const estW = $('#estRatingWhite');
                        const estB = $('#estRatingBlack');
                        if (estW) estW.textContent = ratingW !== null ? `${ratingW}` : '—';
                        if (estB) estB.textContent = ratingB !== null ? `${ratingB}` : '—';
                    }

                    // Approximate accuracy% -> Elo-style performance rating for this one game,
                    // like Chess.com's "Estimated Rating Performance" in its Game Report. Chess.com
                    // doesn't publish its exact formula (same caveat as move classification), so
                    // this piecewise-linear curve is a best-effort reconstruction from widely-cited
                    // accuracy/strength reference points — not a precise reverse-engineering.
                    const ACCURACY_RATING_CURVE = [
                        [100, 3200], [98, 2700], [95, 2400], [90, 2100], [85, 1900],
                        [80, 1700], [70, 1400], [60, 1100], [50, 800], [40, 550], [0, 300],
                    ];

                    function estimatePerformanceRating(accuracy) {
                        if (accuracy === null || accuracy === undefined || isNaN(accuracy)) return null;
                        const pts = ACCURACY_RATING_CURVE;
                        if (accuracy >= pts[0][0]) return pts[0][1];
                        if (accuracy <= pts[pts.length - 1][0]) return pts[pts.length - 1][1];
                        for (let i = 0; i < pts.length - 1; i++) {
                            const [accHi, ratingHi] = pts[i];
                            const [accLo, ratingLo] = pts[i + 1];
                            if (accuracy <= accHi && accuracy >= accLo) {
                                const t = (accuracy - accLo) / (accHi - accLo);
                                return Math.round(ratingLo + t * (ratingHi - ratingLo));
                            }
                        }
                        return null;
                    }

                    function updateEvalBar(scoreCp) {
                        const bar = $('#evalBarWhite');
                        if (scoreCp === null || scoreCp === undefined) {
                            bar.style.height = '50%';
                            return;
                        }
                        // Clamp to ±500cp for display
                        const clamped = Math.max(-500, Math.min(500, scoreCp));
                        // White% = 50 + (clamped/500)*50  => 0%-100%
                        const whitePct = 50 + (clamped / 500) * 50;
                        bar.style.height = whitePct + '%';
                    }

                    function renderMoveList(liveUpdate = false) {
                        const container = $('#moveListContainer');
                        const savedScrollTop = container.scrollTop;
                        const current = analysisPositions[currentMoveIdx];

                        // ── LIVE PATCH: avoid full rebuild during streaming ──
                        if (liveUpdate && container.querySelector('.lichess-move-list')) {
                            container.querySelectorAll('.analysis-move-btn').forEach(btn => {
                                const line = moveListLines[btn.dataset.k];
                                if (!line) return;
                                const pos = line[line.length - 1];
                                btn.classList.toggle('active', pos === current);

                                const { text, color } = formatEvalCell(pos);
                                let evalSpan = btn.querySelector('.eval-score');
                                if (!evalSpan) {
                                    evalSpan = document.createElement('span');
                                    evalSpan.className = 'eval-score';
                                    btn.appendChild(evalSpan);
                                }
                                if (evalSpan.textContent !== text) evalSpan.textContent = text;
                                if (evalSpan.style.color !== color) evalSpan.style.color = color;
                            });
                            return;  // skip full rebuild
                        }

                        // ── FULL REBUILD ──
                        recordActiveLine();
                        moveListLines = [];
                        let html = `<div class="lichess-move-list" style="display:flex; flex-wrap:wrap; align-content:flex-start; padding:8px; font-size:0.9rem; color:#b3b3b2;">`;

                        // One move button; `line` runs from the start position to this move.
                        const renderMoveBtn = (line, isMain) => {
                            const pos = line[line.length - 1];
                            const k = moveListLines.push(line) - 1;
                            const isActive = pos === current ? 'active' : '';
                            const varClass = isMain ? '' : 'variant-move';

                            const { text, color } = formatEvalCell(pos);
                            const evalHtml = `<span class="eval-score" style="font-size:0.65rem; margin-left:6px; color:${color}; font-family:var(--font-mono);">${text}</span>`;

                            const clockHtml = pos.clock ? `<span class="move-clock" style="font-size:0.6rem; color:#aaa; margin-left:4px; font-weight:normal; font-family:var(--font-mono);">(${pos.clock})</span>` : '';

                            return `<button class="analysis-move-btn ${varClass} ${isActive}" data-k="${k}"
                                style="background:none; border:none; color:#ddd; cursor:pointer; padding:4px 6px; border-radius:3px; display:inline-flex; align-items:center;"
                                onclick="window.gotoMoveLine(${k})">${pos.move_san || ''}${clockHtml}${evalHtml}</button>`;
                        };
                        const moveNum = (text) => `<span style="color:#ff9e00; margin:0 2px 0 4px; font-size:0.85rem;">${text}</span>`;

                        // A variation from `line`'s last move onward; its own branches nest in parentheses.
                        const renderVariation = (line) => {
                            let out = '', needNum = true;
                            for (;;) {
                                const pos = line[line.length - 1], ply = line.length - 1;
                                if (ply % 2 === 1) out += moveNum(`${Math.ceil(ply / 2)}.`);
                                else if (needNum) out += moveNum(`${ply / 2}...`);
                                out += renderMoveBtn(line, false);
                                needNum = false;
                                const siblings = childrenOf(line[line.length - 2]);
                                if (siblings[0] === pos && siblings.length > 1) {
                                    for (const alt of siblings.slice(1)) {
                                        out += `<span style="color:#777; display:inline-flex; flex-wrap:wrap; align-items:center;">(${renderVariation([...line.slice(0, -1), alt])})</span>`;
                                    }
                                    needNum = true;
                                }
                                const next = childrenOf(pos)[0];
                                if (!next) return out;
                                line = [...line, next];
                            }
                        };

                        // Main line in numbered rows; a row breaks after any move that has
                        // variations, which are listed right below it.
                        let line = [analysisPositions[0]], row = null;
                        const closeRow = () => { if (row !== null) { html += row + `</div></div>`; row = null; } };
                        for (let pos = childrenOf(line[0])[0]; pos; pos = childrenOf(pos)[0]) {
                            const parent = line[line.length - 1];
                            line = [...line, pos];
                            const ply = line.length - 1;
                            if (ply % 2 === 1 || row === null) {
                                closeRow();
                                row = `<div style="width:100%; display:flex; align-items:center; margin-bottom:2px;">`
                                    + `<div style="width:35px; color:#666; text-align:right; margin-right:8px; font-size:0.85rem;">${Math.ceil(ply / 2)}${ply % 2 === 1 ? '.' : '...'}</div>`
                                    + `<div style="flex:1; display:flex;">`;
                            } else {
                                row += `<div style="width:12px;"></div>`; // spacer
                            }
                            row += renderMoveBtn(line, true);

                            const alternatives = childrenOf(parent).slice(1);
                            if (alternatives.length) {
                                closeRow();
                                html += `<div style="width:100%; background:rgba(255,170,0,0.08); border-left:3px solid #ff9e00; padding:4px 8px; margin:4px 0 6px;">`;
                                for (const alt of alternatives) {
                                    html += `<div style="display:flex; flex-wrap:wrap; align-items:center; gap:2px;">${renderVariation([...line.slice(0, -1), alt])}</div>`;
                                }
                                html += `</div>`;
                            }
                        }
                        closeRow();

                        html += `</div>`;
                        container.innerHTML = html;
                        container.scrollTop = savedScrollTop;

                        // CSS for active move highlight
                        if (!document.getElementById('analysisMoveStyle')) {
                            const s = document.createElement('style');
                            s.id = 'analysisMoveStyle';
                            s.textContent = `.analysis-move-btn:hover{background:#363430 !important;}
                                .analysis-move-btn.active{background:#363430 !important; color:white!important; font-weight:700;}
                                .analysis-move-btn.variant-move.active{background:#45433d !important; color:white!important; border-bottom: 2px solid #ff9e00 !important;}
                                .ann-badge { font-size: 0.6rem; padding: 2px 4px; border-radius: 4px; margin-left: 6px; font-weight: 800; text-transform: uppercase; display: inline-block; vertical-align: middle; }
                                .ann-sigma { background: #06b6d4; color: #fff; }
                                .ann-awesome { background: #22c55e; color: #fff; }
                                .ann-best { background: #3b82f6; color: #fff; }
                                .ann-nice { background: #10b981; color: #fff; }
                                .ann-ok { background: #eab308; color: #000; }
                                .ann-theoretical { background: #a855f7; color: #fff; }
                                .ann-strange { background: #f97316; color: #fff; }
                                .ann-bad { background: #ef4444; color: #fff; }
                                .ann-clown { background: #991b1b; color: #fff; }`;
                            document.head.appendChild(s);
                        }
                    }

                    function formatEvalCell(pos) {
                        if (pos.score === null || pos.score === undefined) return { text: '—', color: 'var(--text-muted)' };
                        const sc = pos.score;
                        const isNum = typeof sc === 'number';
                        const text = isNum ? (sc >= 0 ? `+${sc.toFixed(2)}` : sc.toFixed(2)) : String(sc);
                        const color = isNum
                            ? (sc > 0.3 ? '#4ade80' : sc < -0.3 ? '#f87171' : 'var(--text-secondary)')
                            : '#4ade80';
                        return { text, color };
                    }

                    function renderEvalChart() {
                        const canvas = $('#evalChart');
                        if (!canvas) return;
                        const ctx = canvas.getContext('2d');

                        const labels = analysisPositions.map((p, i) => i === 0 ? 'Start' : p.move_san || `#${i}`);
                        const rawData = analysisPositions.map(p => {
                            if (p.score_cp === null || p.score_cp === undefined) return 0;
                            return Math.max(-800, Math.min(800, p.score_cp)) / 100;
                        });

                        canvas.width = canvas.offsetWidth;

                        if (evalChartInstance) evalChartInstance = null;

                        // Simple canvas chart
                        const w = canvas.width, h = canvas.height;
                        ctx.clearRect(0, 0, w, h);

                        // Draw zero line
                        ctx.strokeStyle = 'rgba(255,255,255,0.15)';
                        ctx.lineWidth = 1;
                        ctx.setLineDash([4, 4]);
                        ctx.beginPath();
                        ctx.moveTo(0, h / 2);
                        ctx.lineTo(w, h / 2);
                        ctx.stroke();
                        ctx.setLineDash([]);

                        if (rawData.length < 2) return;

                        const stepX = w / (rawData.length - 1);
                        const maxVal = 8; // ±8.00

                        const toY = (v) => h / 2 - (v / maxVal) * (h / 2 - 4);
                        const toX = (i) => i * stepX;

                        // Fill area
                        ctx.beginPath();
                        ctx.moveTo(toX(0), h / 2);
                        for (let i = 0; i < rawData.length; i++) {
                            ctx.lineTo(toX(i), toY(rawData[i]));
                        }
                        ctx.lineTo(toX(rawData.length - 1), h / 2);
                        ctx.closePath();
                        const grad = ctx.createLinearGradient(0, 0, 0, h);
                        grad.addColorStop(0, 'rgba(124,92,255,0.5)');
                        grad.addColorStop(0.5, 'rgba(124,92,255,0.1)');
                        grad.addColorStop(1, 'rgba(239,68,68,0.4)');
                        ctx.fillStyle = grad;
                        ctx.fill();

                        // Line
                        ctx.beginPath();
                        ctx.strokeStyle = 'rgba(124,92,255,0.9)';
                        ctx.lineWidth = 2;
                        for (let i = 0; i < rawData.length; i++) {
                            i === 0 ? ctx.moveTo(toX(i), toY(rawData[i])) : ctx.lineTo(toX(i), toY(rawData[i]));
                        }
                        ctx.stroke();

                        // Current position dot
                        ctx.beginPath();
                        ctx.arc(toX(currentMoveIdx), toY(rawData[currentMoveIdx]), 5, 0, Math.PI * 2);
                        ctx.fillStyle = '#fff';
                        ctx.fill();

                        // Click on chart to jump to move
                        canvas.onclick = (e) => {
                            const rect = canvas.getBoundingClientRect();
                            const x = e.clientX - rect.left;
                            const idx = Math.round(x / stepX);
                            gotoMove(Math.max(0, Math.min(idx, analysisPositions.length - 1)));
                        };
                    }

                    // Global function for inline onclick in move list
                    window.gotoMoveLine = (k) => {
                        const line = moveListLines[k];
                        if (!line) return;
                        analysisPositions = [...line];
                        gotoMove(line.length - 1);
                    };

                    initAnalysisTab();

                    // Handle saved analysis URL — restore directly without re-running Stockfish
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('analysis')) {
                        const analysisId = urlParams.get('analysis');
                        setTimeout(async () => {
                            $('#tabBtnAnalysis').click();
                            toast('Loading saved analysis…', 'info');
                            try {
                                const res = await fetch(`/api/analyses/${encodeURIComponent(analysisId)}`);
                                const data = await res.json();
                                if (data.success && data.data && data.data.analysis && data.data.analysis.length > 0) {
                                    const saved = data.data;

                                    // Re-parse PGN headers only (cheap, no engine)
                                    const pgnText = saved.pgn || '';
                                    const headerRe = /\[(\w+)\s+"([^"]*)"\]/g;
                                    let hm;
                                    const headers = {};
                                    while ((hm = headerRe.exec(pgnText)) !== null) headers[hm[1]] = hm[2];
                                    analysisHeaders = headers;
                                    isCurrentGame960 = headers.Variant &&
                                        (headers.Variant.toLowerCase() === 'fischerandom' ||
                                            headers.Variant.toLowerCase() === 'chess960');
                                    updateAnalysisRatings();

                                    // Restore positions directly from saved data
                                    currentAnalysisId = analysisId;
                                    analysisPositions = saved.analysis;
                                    mainPositions = [...analysisPositions];

                                    // Rebuild analysisMovesSan list for move list rendering
                                    analysisMovesSan = analysisPositions.slice(1).map(p => p.move_san || '');

                                    // Restore original PGN text so Export always produces the exact source PGN
                                    $('#pgnInput').value = pgnText;

                                    // Show the layout
                                    $('#analysisPgnImportCard').style.display = 'none';
                                    $('#analysisMainLayout').style.display = 'block';

                                    // Populate player info
                                    $('#infoWhite').textContent = headers.White || '?';
                                    $('#infoBlack').textContent = headers.Black || '?';
                                    $('#infoEvent').textContent = headers.Event && headers.Event !== '?' ? headers.Event : '';
                                    $('#infoDate').textContent = headers.Date || '';
                                    $('#infoResult').textContent = headers.Result || '';

                                    renderMoveList();
                                    renderEvalChart();

                                    // Deferred for the same reason as after PGN batch analysis: the
                                    // board container just went display:none -> block, so chessground
                                    // has no real dimensions yet this tick.
                                    setTimeout(() => {
                                        if (analysisBoard) analysisBoard.redrawAll();
                                        gotoMove(0);
                                    }, 50);
                                    toast('Analysis loaded!', 'success');
                                } else if (data.success && data.data && data.data.pgn) {
                                    // Fallback: analysis not saved, just populate PGN and re-run
                                    currentAnalysisId = analysisId;
                                    $('#pgnInput').value = data.data.pgn;
                                    toast('Re-analyzing (no cached data)…', 'info');
                                    setTimeout(() => { $('#btnStartPgnAnalysis').click(); }, 100);
                                } else {
                                    toast('Saved analysis not found.', 'error');
                                }
                            } catch (e) {
                                console.error('Error loading analysis:', e);
                                toast('Failed to load analysis.', 'error');
                            }
                        }, 500);
                    }
                }

                // ── Initialize App ──
                async function init() {
                    setupEvents();
                    checkConnection();
                    try {
                        const res = await fetch(window.OPENINGS_DB_URL);
                        openingsDatabase = await res.json();
                        buildEcoNameIndex();
                    } catch (e) {
                        console.error('Failed to load openings database:', e);
                    }
                }

                // Many FEN entries share one ECO code (every branch of the Scandinavian
                // is still B01) — prefer whichever name has no ": Variation" suffix as
                // the canonical one for that code, since that's consistently the base
                // line's name in this dataset. Falls back to the first name seen for
                // codes where every entry happens to have a colon.
                function buildEcoNameIndex() {
                    for (const key in openingsDatabase) {
                        const entry = openingsDatabase[key];
                        if (!entry || !entry.eco || !entry.name) continue;
                        const isBaseLine = !entry.name.includes(':');
                        if (!ecoNameIndex[entry.eco] || isBaseLine) {
                            ecoNameIndex[entry.eco] = entry.name;
                        }
                    }
                }



                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }

            })();

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Forge — 管理器</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0 }
        :root {
            --bg: #f8f9fa; --sf: #fff; --bd: #e2e8f0; --tx: #1a202c; --tx2: #718096;
            --pri: #e53e3e; --pri-l: #fff5f5; --blue: #3182ce; --green: #38a169;
            --orange: #dd6b20; --purple: #805ad5; --gray: #a0aec0;
            --get: #38a169; --post: #3182ce; --put: #dd6b20; --del: #e53e3e; --patch: #805ad5;
            --r: 8px; --sh: 0 1px 3px rgba(0,0,0,.08); --sh2: 0 4px 12px rgba(0,0,0,.12);
            --fn: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --mn: 'SF Mono', SFMono-Regular, Consolas, 'Liberation Mono', Menlo, monospace;
        }
        body { font-family: var(--fn); background: var(--bg); color: var(--tx); line-height: 1.5 }
        .hdr {
            background: var(--sf); border-bottom: 1px solid var(--bd); padding: 0 24px;
            display: flex; align-items: center; height: 56px; position: sticky; top: 0; z-index: 100; box-shadow: var(--sh)
        }
        .logo { font-size: 18px; font-weight: 700; color: var(--pri); display: flex; align-items: center; gap: 8px }
        .hdr-r { margin-left: auto; display: flex; align-items: center; gap: 12px }
        .badge { font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 600; text-transform: uppercase }
        .b-dbg { background: #fefcbf; color: #975a16 }
        .b-ver { background: var(--pri-l); color: var(--pri) }
        .tabs { display: flex; background: var(--sf); border-bottom: 1px solid var(--bd); padding: 0 24px }
        .tab { padding: 12px 20px; font-size: 14px; font-weight: 500; color: var(--tx2); cursor: pointer; border-bottom: 2px solid transparent; transition: .15s }
        .tab:hover { color: var(--tx) }
        .tab.on { color: var(--pri); border-color: var(--pri) }
        .box { max-width: 1400px; margin: 0 auto; padding: 24px }
        .pn { display: none }
        .pn.on { display: block }
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px }
        .card {
            background: var(--sf); border: 1px solid var(--bd); border-radius: var(--r); padding: 20px;
            cursor: pointer; transition: .15s; position: relative; overflow: hidden
        }
        .card:hover { box-shadow: var(--sh2); transform: translateY(-1px) }
        .card.on { border-color: var(--pri); box-shadow: 0 0 0 1px var(--pri) }
        .card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px }
        .c-public::before, .t-public { background: var(--blue) }
        .c-client::before, .t-client { background: var(--green) }
        .c-manage::before, .t-manage { background: var(--orange) }
        .c-admin::before, .t-admin { background: var(--pri) }
        .c-unassigned::before, .t-unassigned { background: var(--gray) }
        .c-def::before, .t-def { background: var(--purple) }
        .c-name { font-size: 16px; font-weight: 600; margin-bottom: 4px }
        .c-desc { font-size: 12px; color: var(--tx2); margin-bottom: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
        .c-cnt { font-size: 28px; font-weight: 700 }
        .c-meta { font-size: 11px; color: var(--tx2); margin-top: 4px }
        .bar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap }
        .srch { flex: 1; min-width: 200px; position: relative }
        .srch input {
            width: 100%; padding: 8px 12px 8px 36px; border: 1px solid var(--bd); border-radius: var(--r);
            font-size: 14px; outline: none; transition: border-color .15s
        }
        .srch input:focus { border-color: var(--pri) }
        .srch svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--tx2) }
        .fg { display: flex; gap: 6px; flex-wrap: wrap }
        .afg { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--tx2); cursor: pointer; user-select: none }
        .afg input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--pri); cursor: pointer }
        .fb {
            padding: 6px 14px; border: 1px solid var(--bd); border-radius: 20px; font-size: 13px;
            cursor: pointer; background: var(--sf); color: var(--tx2); transition: .15s; white-space: nowrap
        }
        .fb:hover { border-color: var(--pri); color: var(--pri) }
        .fb.on { background: var(--pri); color: #fff; border-color: var(--pri) }
        .rcnt { font-size: 13px; color: var(--tx2); white-space: nowrap }
        .tw { background: var(--sf); border: 1px solid var(--bd); border-radius: var(--r); overflow-y: auto; max-height: calc(100vh - 240px) }
        table { width: 100%; border-collapse: collapse }
        th {
            padding: 10px 16px; text-align: left; font-size: 12px; font-weight: 600; color: var(--tx2);
            text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid var(--bd); background: #f7fafc; position: sticky; top: 0
        }
        td { padding: 10px 16px; font-size: 13px; border-bottom: 1px solid #f0f0f0; vertical-align: top }
        tr:hover td { background: #fafbfc }
        tr:last-child td { border-bottom: none }
        .rn { font-family: var(--mn); font-size: 13px; font-weight: 500; cursor: pointer }
        .ab { display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 8px; font-size: 11px; background: var(--acc-s, rgba(59,130,246,.15)); color: var(--acc, #3b82f6); cursor: help }
        .rn:hover { color: var(--pri) }
        .ru { font-family: var(--mn); font-size: 12px; color: var(--tx2) }
        .mb { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 700; color: #fff; margin-right: 3px; letter-spacing: .3px }
        .m-GET { background: var(--get) }
        .m-POST { background: var(--post) }
        .m-PUT { background: var(--put) }
        .m-DELETE { background: var(--del) }
        .m-PATCH { background: var(--patch) }
        .tb { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; color: #fff; }
        .emp { padding: 48px; text-align: center; color: var(--tx2); font-size: 14px }
        .mwl { display: flex; flex-wrap: wrap; gap: 4px }
        .mwt { font-size: 11px; padding: 1px 6px; border: 1px solid var(--bd); border-radius: 3px; color: var(--tx2); font-family: var(--mn) }
        .mo { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 200; align-items: center; justify-content: center }
        .mo.show { display: flex }
        .mdl { background: var(--sf); border-radius: 12px; width: 90%; max-width: 600px; max-height: 80vh; overflow-y: auto; box-shadow: var(--sh2) }
        .mh { padding: 16px 24px; border-bottom: 1px solid var(--bd); display: flex; align-items: center; justify-content: space-between }
        .mh h3 { font-size: 16px; font-weight: 600 }
        .mx { width: 28px; height: 28px; border: none; background: none; cursor: pointer; font-size: 20px; color: var(--tx2); border-radius: 4px; display: flex; align-items: center; justify-content: center }
        .mx:hover { background: #f0f0f0 }
        .mbd { padding: 24px }
        .dr { margin-bottom: 16px }
        .dl { font-size: 11px; font-weight: 600; color: var(--tx2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px }
        .dv { font-family: var(--mn); font-size: 13px; word-break: break-all }
        .cs { background: var(--sf); border: 1px solid var(--bd); border-radius: var(--r); padding: 24px; margin-bottom: 20px }
        .cs h3 { font-size: 15px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px }
        .cg { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px }
        .fd { display: flex; flex-direction: column; gap: 4px }
        .fd label { font-size: 12px; font-weight: 600; color: var(--tx2) }
        .fd input, .fd select { padding: 8px 12px; border: 1px solid var(--bd); border-radius: var(--r); font-size: 14px; outline: none; font-family: var(--fn) }
        .fd input:focus, .fd select:focus { border-color: var(--pri) }
        .fdc { flex-direction: row; align-items: center; gap: 8px; padding-top: 22px; }
        .fdc input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--pri) }
        .je { width: 100%; min-height: 400px; padding: 16px; border: 1px solid var(--bd); border-radius: var(--r); font-family: var(--mn); font-size: 13px; line-height: 1.6; resize: vertical; outline: none; tab-size: 2 }
        .je:focus { border-color: var(--pri) }
        .jerr { color: var(--del); font-size: 12px; margin-top: 4px; display: none }
        .btn { padding: 8px 20px; border: none; border-radius: var(--r); font-size: 14px; font-weight: 500; cursor: pointer; transition: .15s }
        .bp { background: var(--pri); color: #fff }
        .bp:hover { background: #c53030 }
        .bs { background: #edf2f7; color: var(--tx) }
        .bs:hover { background: #e2e8f0 }
        .bg { display: flex; gap: 8px; margin-top: 16px }
        .toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: var(--r); color: #fff; font-size: 14px; z-index: 300; transform: translateY(100px); opacity: 0; transition: .3s }
        .toast.show { transform: translateY(0); opacity: 1 }
        .toast-ok { background: var(--green) }
        .toast-err { background: var(--del) }
        .btn-refresh { padding: 6px 14px; border: 1px solid var(--bd); border-radius: var(--r); font-size: 13px; cursor: pointer; background: var(--sf); color: var(--tx2); transition: .15s; display: flex; align-items: center; gap: 4px }
        .btn-refresh:hover { border-color: var(--pri); color: var(--pri) }
        .btn-refresh.loading { opacity: .6; pointer-events: none }
        .btn-refresh svg { width: 14px; height: 14px }
        .btn-refresh.spinning svg { animation: spin .8s linear infinite }
        @keyframes spin { to { transform: rotate(360deg) } }
    </style>
</head>
<body>
<header class="hdr">
    <div class="logo">
        <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2">
            <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2v-4M9 21H5a2 2 0 01-2-2v-4" />
        </svg>
        Route Forge 管理器
    </div>
    <div class="hdr-r">
        <button class="btn-refresh" id="btn-refresh" title="刷新路由数据">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 4v6h6" />
                <path d="M23 20v-6h-6" />
                <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15" />
            </svg>
            刷新
        </button>
        <span class="badge b-ver">v{{ $globalConfig['scheme_version'] }}</span>
        <span class="badge b-dbg">调试模式</span>
    </div>
</header>
<nav class="tabs">
    <div class="tab on" data-tab="overview">总览</div>
    <div class="tab" data-tab="routes">路由</div>
    <div class="tab" data-tab="config">配置</div>
</nav>
<div class="box">
    <div id="p-overview" class="pn on">
        <div class="cards" id="tier-cards"></div>
        <div id="ov-stats"></div>
    </div>
    <div id="p-routes" class="pn">
        <div class="bar">
            <div class="srch">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
                <input type="text" id="si" placeholder="搜索路由名称、URI、中间件..."></div>
            <div class="fg" id="tf"></div>
            <div class="fg" id="mf">
                <button class="fb" data-m="GET">GET</button>
                <button class="fb" data-m="POST">POST</button>
                <button class="fb" data-m="PUT">PUT</button>
                <button class="fb" data-m="DELETE">DELETE</button>
            </div>
            <label class="afg" title="仅显示别名条目（旧路由名 → 真实路由名）">
                <input type="checkbox" id="af"> 仅别名
            </label>
            <span class="rcnt" id="rc"></span>
        </div>
        <div class="tw">
            <table>
                <thead>
                <tr>
                    <th>路由名</th>
                    <th>层级</th>
                    <th>方法</th>
                    <th>URI</th>
                    <th>中间件</th>
                </tr>
                </thead>
                <tbody id="rtb"></tbody>
            </table>
        </div>
    </div>
    <div id="p-config" class="pn">
        <div class="cs"><h3>全局设置</h3>
            <div class="cg" id="gf"></div>
        </div>
        <div class="cs">
            <h3>层级配置 <span style="font-weight:400;font-size:12px;color:var(--tx2)">（JSON 格式编辑 levels 配置）</span>
            </h3>
            <textarea class="je" id="le" spellcheck="false"></textarea>
            <div class="jerr" id="jerr"></div>
            <div class="bg">
                <button class="btn bp" id="bsave">保存配置</button>
                <button class="btn bs" id="breset">重置</button>
            </div>
        </div>
    </div>
</div>
<div class="mo" id="modal">
    <div class="mdl">
        <div class="mh"><h3 id="mt">路由详情</h3>
            <button class="mx" id="mc">&times;</button>
        </div>
        <div class="mbd" id="mbd"></div>
    </div>
</div>
<div class="toast" id="toast"></div>
<script>
  const CFG = @json(['tiers'=>$tiers,'levelsConfig'=>$levelsConfig,'globalConfig'=>$globalConfig]);
  const D = { routes: [], tierCounts: {} };
  const TC = { public: 'blue', client: 'green', manage: 'orange', admin: 'pri', unassigned: 'gray' };
  const tc = t => TC[t] || 'purple';
  const esc = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  const S = { tab: 'overview', q: '', tf: null, mf: null, aliasOnly: false };
  const $ = s => document.querySelector(s);
  const $$ = s => document.querySelectorAll(s);
  let searchTimer = null;
  const TPL = {
    method: m => `<span class="mb m-${m}">${m}</span>`,
    middleware: arr => arr.length ? `<div class="mwl">${arr.map(m => `<span class="mwt">${esc(m)}</span>`).join('')}</div>` : '<span style="color:var(--tx2)">—</span>',
    tierBadge: t => `<span class="tb ${TC[t] ? 't-' + t : 't-def'}">${esc(t)}</span>`,
    empty: '<tr><td colspan="5" class="emp">没有匹配的路由</td></tr>'
  };

  document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    loadRoutes();
    renderCFG();
    bindEvts();
  });

  async function loadRoutes() {
    const btn = $('#btn-refresh');
    btn.classList.add('spinning', 'loading');
    try {
      const res = await fetch(window.location.pathname.replace(/\/$/, '') + '/api/routes');
      const data = await res.json();
      D.routes = data.routes;
      D.tierCounts = data.tiers;
      renderOV();
      renderTF();
      renderRT();
    } catch (e) {
      toast('加载路由数据失败: ' + e.message, 'err');
    } finally {
      btn.classList.remove('spinning', 'loading');
    }
  }

  function initTabs() {
    $$('.tab').forEach(t => t.addEventListener('click', () => swTab(t.dataset.tab)));
  }

  function swTab(tab) {
    S.tab = tab;
    $$('.tab').forEach(t => t.classList.toggle('on', t.dataset.tab === tab));
    $$('.pn').forEach(p => p.classList.toggle('on', p.id === 'p-' + tab));
  }

  function renderOV() {
    const c = $('#tier-cards');
    const all = [...CFG.tiers.map(t => t.name)];
    if (D.tierCounts['unassigned']) all.push('unassigned');
    const total = D.routes.length;
    const loadMap = { eager: 'eager（预加载）', lazy: 'lazy（按需加载）' };
    let h = `<div class="card" onclick="filterT(null)"><div class="c-name">全部路由</div><div class="c-desc">所有已命名路由</div><div class="c-cnt">${total}</div></div>`;
    const frag = document.createDocumentFragment();
    all.forEach(n => {
      const info = CFG.tiers.find(t => t.name === n);
      const desc = info ? info.description : '未命中任何层级的路由';
      const load = info ? loadMap[info.load] || info.load : '';
      const div = document.createElement('div');
      div.className = `card ${TC[n] ? 'c-' + n : 'c-def'}`;
      div.onclick = () => filterT(n);
      div.innerHTML = `<div class="c-name">${esc(n)}</div><div class="c-desc">${esc(desc)}</div><div class="c-cnt">${D.tierCounts[n] || 0}</div>${load ? `<div class="c-meta">${load}</div>` : ''}`;
      frag.appendChild(div);
    });
    c.innerHTML = h;
    c.appendChild(frag);
    const ms = {};
    D.routes.forEach(r => r.methods.forEach(m => ms[m] = (ms[m] || 0) + 1));
    $('#ov-stats').innerHTML = `<div style="background:var(--sf);border:1px solid var(--bd);border-radius:var(--r);padding:16px 20px;font-size:14px"><strong>HTTP 方法分布：</strong>&nbsp;&nbsp;${Object.entries(ms).map(([m, c]) => `<span class="mb m-${m}">${m}</span> ${c}`).join('&nbsp;&nbsp;&nbsp;')}</div>`;
  }

  function renderTF() {
    const a = ['all', ...CFG.tiers.map(t => t.name)];
    if (D.tierCounts['unassigned']) a.push('unassigned');
    $('#tf').innerHTML = a.map(t => `<button class="fb${t === 'all' ? ' on' : ''}" data-t="${t}">${t === 'all' ? '全部' : t}</button>`).join('');
  }

  function renderRT() {
    const f = filtered();
    const tb = $('#rtb');
    $('#rc').textContent = f.length + ' 条路由';
    if (!f.length) { tb.innerHTML = TPL.empty; return; }
    const frag = document.createDocumentFragment();
    f.forEach(r => {
      const tr = document.createElement('tr');
      const ab = r.alias_of ? `<span class="ab" title="别名 → ${esc(r.alias_of)}（旧名仍可被前端使用）">别名</span>` : '';
      tr.innerHTML = `<td><span class="rn" onclick="showD('${esc(r.name)}')">${esc(r.name)}</span>${ab}</td><td>${TPL.tierBadge(r.tier)}</td><td>${r.methods.map(TPL.method).join('')}</td><td class="ru">${esc(r.uri)}</td><td>${TPL.middleware(r.middleware)}</td>`;
      frag.appendChild(tr);
    });
    tb.innerHTML = '';
    tb.appendChild(frag);
  }

  function filtered() {
    const q = S.q ? S.q.toLowerCase() : null;
    return D.routes.filter(r =>
      (!S.tf || r.tier === S.tf) &&
      (!S.mf || r.methods.includes(S.mf)) &&
      (!S.aliasOnly || r.alias_of) &&
      (!q || (r.name + ' ' + r.uri + ' ' + r.middleware.join(' ')).toLowerCase().includes(q))
    );
  }

  function filterT(t) {
    S.tf = t;
    swTab('routes');
    renderTF();
    $$('#tf .fb').forEach(b => b.classList.toggle('on', b.dataset.t === (t || 'all')));
    renderRT();
  }

  function showD(name) {
    const r = D.routes.find(x => x.name === name);
    if (!r) return;
    const ps = r.parameters.length ? r.parameters.map(p => `<div style="font-family:var(--mn)">${esc(p)}${r.parameter_defaults[p] !== undefined ? ` <span style="color:var(--tx2)">(默认: ${esc(String(r.parameter_defaults[p]))})</span>` : ''}</div>`).join('') : '<span style="color:var(--tx2)">无参数</span>';
    const mw = r.middleware.length ? r.middleware.map(m => `<span class="mwt">${esc(m)}</span>`).join(' ') : '<span style="color:var(--tx2)">无</span>';
    $('#mt').textContent = r.name;
    const aliasRow = r.alias_of ? `<div class="dr"><div class="dl">别名指向</div><div class="dv">${esc(r.alias_of)}</div></div>` : '';
    $('#mbd').innerHTML = `<div class="dr"><div class="dl">层级</div><div>${TPL.tierBadge(r.tier)}</div></div>${aliasRow}<div class="dr"><div class="dl">URI</div><div class="dv">${esc(r.uri)}</div></div><div class="dr"><div class="dl">HTTP 方法</div><div>${r.methods.map(TPL.method).join(' ')}</div></div><div class="dr"><div class="dl">路径参数</div><div>${ps}</div></div><div class="dr"><div class="dl">中间件</div><div class="mwl">${mw}</div></div>`;
    $('#modal').classList.add('show');
  }

  function renderCFG() {
    const g = CFG.globalConfig;
    const fields = [
      { k: 'endpoint_prefix', l: '端点前缀', t: 'text', v: g.endpoint_prefix },
      { k: 'url_prefix', l: 'URL 前缀', t: 'text', v: g.url_prefix || '' },
      { k: 'cache_ttl', l: '缓存 TTL (秒)', t: 'number', v: g.cache_ttl ?? 3600 },
      { k: 'cache_driver', l: '缓存驱动', t: 'text', v: g.cache_driver || '' },
      { k: 'scheme_version', l: '格式版本', t: 'number', v: g.scheme_version },
      { k: 'strict_mode', l: '严格模式', t: 'check', v: !!g.strict_mode },
    ];
    $('#gf').innerHTML = fields.map(f => f.t === 'check' ? `<div class="fd fdc"><input type="checkbox" id="cf-${f.k}" data-k="${f.k}" ${f.v ? 'checked' : ''}><label for="cf-${f.k}">${f.l}</label></div>` : `<div class="fd"><label>${f.l}</label><input type="${f.t}" id="cf-${f.k}" data-k="${f.k}" value="${esc(String(f.v))}"></div>`).join('');
    $('#le').value = JSON.stringify(CFG.levelsConfig, null, 2);
  }

  function bindEvts() {
    $('#si').addEventListener('input', e => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => { S.q = e.target.value; renderRT(); }, 150);
    });
    $('#tf').addEventListener('click', e => {
      if (!e.target.classList.contains('fb')) return;
      const t = e.target.dataset.t;
      S.tf = t === 'all' ? null : t;
      $$('#tf .fb').forEach(b => b.classList.toggle('on', b === e.target));
      renderRT();
    });
    $('#mf').addEventListener('click', e => {
      const b = e.target.closest('.fb');
      if (!b) return;
      const m = b.dataset.m;
      const wasOn = b.classList.contains('on');
      S.mf = wasOn ? null : m;
      $$('#mf .fb').forEach(x => x.classList.remove('on'));
      if (!wasOn) b.classList.add('on');
      renderRT();
    });
    $('#af').addEventListener('change', e => {
      S.aliasOnly = e.target.checked;
      renderRT();
    });
    $('#mc').addEventListener('click', () => $('#modal').classList.remove('show'));
    $('#modal').addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.classList.remove('show'); });
    $('#bsave').addEventListener('click', saveCfg);
    $('#breset').addEventListener('click', renderCFG);
    $('#le').addEventListener('input', () => { $('#jerr').style.display = 'none'; });
    $('#btn-refresh').addEventListener('click', loadRoutes);
  }

  async function saveCfg() {
    const le = $('#le'), je = $('#jerr');
    let levels;
    try { levels = JSON.parse(le.value); } catch (e) { je.textContent = 'JSON 解析错误: ' + e.message; je.style.display = 'block'; return; }
    const global = {};
    $$('#gf input').forEach(inp => {
      const k = inp.dataset.k;
      if (inp.type === 'checkbox') global[k] = inp.checked;
      else if (inp.type === 'number') global[k] = inp.value === '' ? null : Number(inp.value);
      else global[k] = inp.value === '' ? null : inp.value;
    });
    try {
      const res = await fetch(window.location.pathname.replace(/\/$/, '') + '/api/config', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ levels, global }),
      });
      const data = await res.json();
      data.success ? toast('配置保存成功', 'ok') : toast(data.error || '保存失败', 'err');
    } catch (e) { toast('网络错误: ' + e.message, 'err'); }
  }

  function toast(msg, type) {
    const t = $('#toast');
    t.textContent = msg;
    t.className = 'toast toast-' + type + ' show';
    setTimeout(() => t.classList.remove('show'), 3000);
  }
</script>
</body>
</html>

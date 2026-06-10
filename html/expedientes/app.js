(function () {
  'use strict';

  var API = {
    consulta: './api/consulta.php',
    status: './api/status.php',
    me: './api/me.php',
    setup: './api/setup.php',
    login: './api/login.php',
    logout: './api/logout.php',
    dashboard: './api/dashboard.php',
    users: './api/users.php'
  };

  var INITIAL_LIMIT = 12;
  var ESTADOS = {
    I: { label: 'Ingresado', className: 'status-progress' },
    E: { label: 'En trámite', className: 'status-progress' },
    A: { label: 'Archivado', className: 'status-done' },
    X: { label: 'Terminado', className: 'status-done' },
    T: { label: 'Terminado', className: 'status-done' },
    C: { label: 'Cerrado', className: 'status-paused' },
    P: { label: 'Paralizado', className: 'status-paused' },
    N: { label: 'Anulado', className: 'status-cancelled' }
  };

  var state = {
    lastParams: null,
    expediente: null,
    movimientos: [],
    meta: null,
    session: { authenticated: false, user: null, setup_required: false }
  };

  function byId(id) { return document.getElementById(id); }

  var dom = {
    form: byId('searchForm'),
    numero: byId('numero'),
    letra: byId('letra'),
    ano: byId('ano'),
    button: byId('searchButton'),
    clear: byId('clearButton'),
    message: byId('formMessage'),
    result: byId('resultPanel'),
    hero: byId('appHero'),
    status: byId('syncStatus'),
    loginButton: byId('loginButton'),
    dashboardButton: byId('dashboardButton'),
    logoutButton: byId('logoutButton')
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function normalizeText(value) {
    var clean = String(value == null ? '' : value).replace(/\r/g, '').trim();
    return clean === '' || clean === '0' || clean === '0000-00-00 00:00:00' ? '—' : clean;
  }

  function isEmptyDate(value) {
    return !value || String(value).indexOf('1900') === 0 || value === '0000-00-00 00:00:00';
  }

  function parseDate(value) {
    if (isEmptyDate(value)) return null;
    var parsed = new Date(String(value).replace(' ', 'T'));
    return isNaN(parsed.getTime()) ? null : parsed;
  }

  function formatDate(value, options) {
    options = options || {};
    var parsed = parseDate(value);
    if (!parsed) return '—';
    var date = parsed.toLocaleDateString('es-AR', { day: '2-digit', month: options.short ? '2-digit' : 'long', year: 'numeric' });
    if (!options.withTime) return date;
    return date + ' · ' + parsed.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
  }

  function statusInfo(code) {
    var key = String(code == null ? '' : code).trim().toUpperCase();
    return ESTADOS[key] || { label: key || 'Sin estado', className: 'status-neutral' };
  }

  function setFormMessage(message) {
    if (!dom.message) return;
    dom.message.textContent = message || '';
    dom.message.classList.toggle('is-error', Boolean(message));
  }

  function setLoading(isLoading) {
    if (!dom.button) return;
    dom.button.disabled = isLoading;
    var label = dom.button.querySelector('span:last-child');
    if (label) label.textContent = isLoading ? 'Consultando…' : 'Consultar expediente';
  }


  function setInternalMode(enabled) {
    if (dom.hero) dom.hero.classList.toggle('is-hidden', Boolean(enabled));
    document.body.classList.toggle('internal-mode', Boolean(enabled));
  }

  function renderLoading(label) {
    dom.result.innerHTML = '<article class="loading-card">' +
      '<span class="spinner" aria-hidden="true"></span>' +
      '<span>' + escapeHtml(label || 'Buscando expediente en el sistema…') + '</span>' +
      '</article>';
  }

  function renderNotice(type, title, message, detail) {
    var iconClass = type === 'error' ? 'status-cancelled' : 'status-progress';
    dom.result.innerHTML = '<article class="notice-card">' +
      '<span class="notice-icon ' + iconClass + '" aria-hidden="true">' +
      '<svg viewBox="0 0 24 24"><path d="M11 7h2v7h-2V7Zm0 9h2v2h-2v-2Zm1-14a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18.2A8.2 8.2 0 1 1 12 3.8a8.2 8.2 0 0 1 0 16.4Z"/></svg>' +
      '</span>' +
      '<p class="eyebrow">' + (type === 'error' ? 'Error' : 'Sin resultados') + '</p>' +
      '<h2>' + escapeHtml(title) + '</h2>' +
      '<p>' + escapeHtml(message) + '</p>' +
      (detail ? '<div class="empty-tips"><span>' + escapeHtml(detail) + '</span></div>' : '') +
      '</article>';
  }

  function getSearchParams() {
    var numero = dom.numero.value.trim();
    var letra = dom.letra.value.trim().toUpperCase();
    var ano = dom.ano.value.trim();
    if (!numero || !ano) return { error: 'Completá número y año para realizar la consulta.' };
    if (!/^\d{1,10}$/.test(numero)) return { error: 'El número de expediente debe contener solo dígitos.' };
    if (letra && !/^[A-Z]$/.test(letra)) return { error: 'La letra debe ser una única letra.' };
    if (!/^\d{4}$/.test(ano)) return { error: 'El año debe tener 4 dígitos.' };
    var year = parseInt(ano, 10);
    var currentYear = new Date().getFullYear() + 1;
    if (year < 1990 || year > currentYear) return { error: 'El año debe estar entre 1990 y ' + currentYear + '.' };
    return { numero: numero, letra: letra, ano: ano };
  }

  function buildQuery(params, offset) {
    var query = new URLSearchParams({ numero: params.numero, ano: params.ano, limit: String(INITIAL_LIMIT), offset: String(offset || 0) });
    if (params.letra) query.set('letra', params.letra);
    return query;
  }

  function fetchJson(url, options) {
    return fetch(url, options || {}).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || payload.error) {
          throw new Error(payload.error || statusToMessage(response.status));
        }
        return payload;
      });
    });
  }

  function consultarExpediente(event) {
    event.preventDefault();
    setFormMessage('');
    var params = getSearchParams();
    if (params.error) {
      setFormMessage(params.error);
      return;
    }
    setLoading(true);
    renderLoading();
    fetchJson(API.consulta + '?' + buildQuery(params, 0).toString(), { headers: { Accept: 'application/json' } })
      .then(function (payload) {
        if (!payload.expediente) {
          var label = 'N° ' + params.numero + (params.letra ? '/' + params.letra : '') + ' · ' + params.ano;
          renderNotice('empty', 'Expediente no encontrado', 'Verificá los datos ingresados o consultá en Mesa de Entradas.', label);
          return;
        }
        state.lastParams = params;
        state.expediente = payload.expediente;
        state.movimientos = payload.movimientos || [];
        state.meta = payload.meta || null;
        renderExpediente(state.expediente, state.movimientos, state.meta);
      })
      .catch(function (error) {
        console.error(error);
        renderNotice('error', 'No se pudo realizar la consulta', error.message || 'No se pudo conectar con la API.');
      })
      .then(function () { setLoading(false); }, function () { setLoading(false); });
  }

  function statusToMessage(status) {
    if (status === 400) return 'Los datos enviados no tienen el formato esperado.';
    if (status === 429) return 'Se realizaron demasiadas consultas. Esperá un momento y volvé a intentar.';
    if (status >= 500) return 'El servidor no pudo procesar la consulta en este momento.';
    return 'Ocurrió un error al consultar el expediente.';
  }

  function expedienteId(expediente) {
    var letra = normalizeText(expediente.LETRA);
    return 'N° ' + normalizeText(expediente.NUMERO) + (letra !== '—' ? '/' + letra : '') + ' · ' + normalizeText(expediente.ANO);
  }

  function renderExpediente(expediente, movimientos, meta) {
    var info = statusInfo(expediente.ESTADO);
    var sectorActual = normalizeText(expediente.SECTACTUAL_NOMBRE || expediente.SECTACTUAL);
    var sectorInicia = normalizeText(expediente.SECTORINICIA_NOMBRE || expediente.SECTORINICIA);
    var motivo = normalizeText(expediente.MOTIVO);
    var iniciador = normalizeText(expediente.EXTERNOINICIA || expediente.INICIADOR);
    var ultimaActualizacion = expediente.updated_at || expediente.FECHACARGA;

    dom.result.innerHTML = '<article class="exp-card">' +
      '<header class="exp-head"><div class="exp-title"><small>Expediente</small><h2>' + escapeHtml(expedienteId(expediente)) + '</h2><p class="exp-subtitle">Información pública del trámite y recorrido registrado por sectores municipales.</p></div><span class="status-badge ' + info.className + '">' + escapeHtml(info.label) + '</span></header>' +
      (Number(expediente.ANULADO) === 1 ? '<div class="annulled">⚠ Expediente anulado</div>' : '') +
      '<section class="quick-sector" aria-label="Sector actual"><span class="sector-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 3.5 9.35l.9 1.2L6 9.35V20h12V9.35l1.6 1.2.9-1.2L12 3Zm4.5 15.5h-9V8.22L12 4.86l4.5 3.36V18.5ZM10 11h4v7h-4v-7Z"/></svg></span><div><span>Sector / área actual</span><strong>' + escapeHtml(sectorActual) + '</strong></div></section>' +
      '<div class="exp-body"><section class="data-grid" aria-label="Datos principales del expediente">' +
      dataItem('Fecha de inicio', formatDate(expediente.FECHAINICIO)) +
      dataItem('Tipo de expediente', normalizeText(expediente.TIPOEXPEDIENTE)) +
      dataItem('Iniciado por', iniciador) +
      dataItem('Sector iniciador', sectorInicia) +
      dataItem('Destino informado', normalizeText(expediente.DESTINO || expediente.SECTORDESTINO)) +
      dataItem('Última actualización', formatDate(ultimaActualizacion, { withTime: true })) +
      '</section>' +
      (motivo !== '—' ? '<section class="topic-box" aria-label="Motivo o asunto"><span>Motivo / asunto</span><p>' + escapeHtml(motivo) + '</p></section>' : '') +
      '<section class="timeline-section" aria-labelledby="timelineTitle"><div class="section-title"><h3 id="timelineTitle">Historial de movimientos</h3><span class="count-pill">' + movementCountLabel(movimientos, meta) + '</span></div>' +
      renderMovementsTable(movimientos) + renderLoadMore(meta) + '</section></div></article>';
  }

  function dataItem(label, value) {
    return '<div class="data-item"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
  }

  function movementCountLabel(movimientos, meta) {
    var total = Number((meta && meta.movimientos_total) || movimientos.length);
    var shown = movimientos.length;
    if (total > shown) return shown + ' de ' + total + ' movimientos';
    return total + ' ' + (total === 1 ? 'movimiento' : 'movimientos');
  }

  function renderLoadMore(meta) {
    if (!meta || !meta.has_more) return '';
    return '<div class="load-more-wrap"><button class="btn-load-more" id="loadMoreButton" type="button">Ver más movimientos</button></div>';
  }

  function renderMovementsTable(movimientos) {
    if (!movimientos.length) {
      return '<article class="notice-card"><h2>Sin movimientos registrados</h2><p>El expediente existe, pero todavía no hay movimientos sincronizados para mostrar.</p></article>';
    }
    var rows = movimientos.map(function (mov, index) { return renderMovementRow(mov, index === 0); }).join('');
    return '<div class="mov-table-wrap"><table class="mov-table"><thead><tr><th>Fecha</th><th>Sector</th><th>Proveniente</th><th>Estado</th><th>Fojas</th><th>Observaciones</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  function renderMovementRow(movimiento, isCurrent) {
    var sector = normalizeText(movimiento.SECTORACTUAL_NOMBRE || movimiento.SECTORACTUAL);
    return '<tr class="' + (isCurrent ? 'is-current' : '') + '">' +
      '<td>' + escapeHtml(formatDate(movimiento.FECHAHORA, { withTime: true })) + '</td>' +
      '<td><strong>' + escapeHtml(sector) + '</strong>' + (isCurrent ? '<span class="current-tag">Actual</span>' : '') + '</td>' +
      '<td>' + escapeHtml(normalizeText(movimiento.SECTORPROVENIENTE)) + '</td>' +
      '<td>' + escapeHtml(statusInfo(movimiento.ESTADOACTUAL).label) + '</td>' +
      '<td>' + escapeHtml(normalizeText(movimiento.FOJAS)) + '</td>' +
      '<td>' + escapeHtml(normalizeText(movimiento.OBSERVACIONES)) + '</td>' +
      '</tr>';
  }

  function loadMoreMovements() {
    if (!state.lastParams || !state.meta || !state.meta.has_more) return;
    var button = byId('loadMoreButton');
    if (button) {
      button.disabled = true;
      button.textContent = 'Cargando movimientos…';
    }
    fetchJson(API.consulta + '?' + buildQuery(state.lastParams, state.movimientos.length).toString(), { headers: { Accept: 'application/json' } })
      .then(function (payload) {
        state.movimientos = state.movimientos.concat(payload.movimientos || []);
        state.meta = payload.meta || state.meta;
        renderExpediente(state.expediente, state.movimientos, state.meta);
      })
      .catch(function (error) {
        console.error(error);
        if (button) {
          button.disabled = false;
          button.textContent = 'No se pudo cargar. Reintentar';
        }
      });
  }

  function clearForm() {
    setInternalMode(false);
    dom.form.reset();
    setFormMessage('');
    state.lastParams = null;
    state.expediente = null;
    state.movimientos = [];
    state.meta = null;
    dom.result.innerHTML = '<article class="empty-state"><span class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3.5h7.25L19 8.25V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20V5a1.5 1.5 0 0 1 2-1.5Zm7 1.8V8.5h3.2L14 5.3ZM7 5v15h10.5V10H12.5V5H7Zm2 8h6v1.5H9V13Zm0 3h6v1.5H9V16Zm0-6h3v1.5H9V10Z"/></svg></span><p class="eyebrow">Inicio</p><h2>Listo para consultar</h2><p>Completá número y año para ver el estado del expediente, su área actual y el recorrido registrado por SIGAP.</p></article>';
    dom.numero.focus();
  }

  function apiPost(url, data) {
    return fetchJson(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data || {}) });
  }

  function loadSession() {
    return fetch(API.me, { headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (payload) { state.session = payload; updateHeaderAuth(); })
      .catch(function () { state.session = { authenticated: false, user: null, setup_required: false }; updateHeaderAuth(); });
  }

  function updateHeaderAuth() {
    var logged = Boolean(state.session && state.session.authenticated);
    dom.loginButton.classList.toggle('is-hidden', logged);
    dom.dashboardButton.classList.toggle('is-hidden', !logged);
    dom.logoutButton.classList.toggle('is-hidden', !logged);
    if (logged && state.session.user) dom.dashboardButton.textContent = 'Dashboard · ' + (state.session.user.name || state.session.user.username);
  }

  function renderLogin() {
    setInternalMode(true);
    var setup = Boolean(state.session && state.session.setup_required);
    dom.result.innerHTML = '<section class="admin-shell"><article class="admin-card">' +
      '<p class="eyebrow">' + (setup ? 'Configuración inicial' : 'Acceso interno') + '</p>' +
      '<h2>' + (setup ? 'Crear primer administrador' : 'Ingreso de empleados') + '</h2>' +
      '<p>' + (setup ? 'No hay usuarios configurados. Creá el primer administrador local.' : 'Ingresá con tu usuario para ver dashboard y gestión de usuarios.') + '</p>' +
      '<form id="authForm" class="admin-form">' +
      (setup ? '<label class="field"><span>Nombre completo</span><input name="name" type="text" autocomplete="name" required></label>' : '') +
      '<label class="field"><span>Usuario</span><input name="username" type="text" autocomplete="username" required></label>' +
      '<label class="field"><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>' +
      '<button class="btn-primary" type="submit">' + (setup ? 'Crear administrador' : 'Ingresar') + '</button>' +
      '<div class="form-message" id="authMessage" role="alert"></div>' +
      '</form></article></section>';
  }

  function submitAuth(form) {
    var data = formToObject(form);
    var message = byId('authMessage');
    apiPost(state.session.setup_required ? API.setup : API.login, data)
      .then(function () { return loadSession(); })
      .then(renderDashboard)
      .catch(function (error) { showInlineError(message, error.message); });
  }

  function logout() {
    apiPost(API.logout)
      .then(function () { return loadSession(); }, function () { return loadSession(); })
      .then(clearForm);
  }

  function renderDashboard() {
    setInternalMode(true);
    if (!state.session.authenticated) {
      renderLogin();
      return;
    }
    renderLoading('Cargando dashboard…');
    fetchJson(API.dashboard, { headers: { Accept: 'application/json' } })
      .then(function (dashboard) {
        if (state.session.user && state.session.user.role === 'admin') {
          return renderUsersSection().then(function (usersHtml) { renderDashboardHtml(dashboard, usersHtml); });
        }
        renderDashboardHtml(dashboard, '');
        return null;
      })
      .catch(function (error) { renderNotice('error', 'No se pudo abrir el dashboard', error.message); });
  }

  function renderDashboardHtml(dashboard, usersHtml) {
    dom.result.innerHTML = '<section class="admin-shell"><article class="admin-card">' +
      '<p class="eyebrow">Panel interno</p><h2>Dashboard municipal</h2>' +
      '<p>Resumen operativo de expedientes sincronizados. Última actualización: ' + escapeHtml(formatDate(dashboard.ultima_sync, { withTime: true })) + '</p>' +
      '<div class="stats-grid">' + statCard('Expedientes', dashboard.totals.expedientes) + statCard('Movimientos', dashboard.totals.movimientos) + statCard('Sectores vigentes', dashboard.totals.sectores) + '</div>' +
      '<div class="dashboard-grid">' + miniTable('Estados', dashboard.by_estado, 'ESTADO') + miniTable('Sectores con más expedientes', dashboard.by_sector, 'sector') + '</div>' +
      '</article>' + usersHtml + '</section>';
  }

  function statCard(label, value) {
    return '<div class="stat-card"><span>' + escapeHtml(label) + '</span><strong>' + Number(value || 0).toLocaleString('es-AR') + '</strong></div>';
  }

  function miniTable(title, rows, labelKey) {
    rows = rows || [];
    return '<div class="mini-table"><h3>' + escapeHtml(title) + '</h3><table><tbody>' + rows.map(function (row) {
      return '<tr><td>' + escapeHtml(normalizeText(row[labelKey])) + '</td><td>' + Number(row.total || 0).toLocaleString('es-AR') + '</td></tr>';
    }).join('') + '</tbody></table></div>';
  }

  function renderUsersSection() {
    return fetchJson(API.users, { headers: { Accept: 'application/json' } }).then(function (payload) {
      var users = payload.users || [];
      var rows = users.map(function (user) {
        return '<tr><td>' + escapeHtml(user.username) + '</td><td>' + escapeHtml(user.name) + '</td><td>' + escapeHtml(user.role) + '</td><td>' + (user.active ? 'Activo' : 'Inactivo') + '</td><td>' + escapeHtml(formatDate(user.last_login_at, { withTime: true })) + '</td><td><button class="table-action" data-user-toggle="' + escapeHtml(user.id) + '" data-active="' + (user.active ? '0' : '1') + '">' + (user.active ? 'Desactivar' : 'Activar') + '</button></td></tr>';
      }).join('');
      return '<article class="admin-card"><p class="eyebrow">Administración</p><h2>Gestión de usuarios</h2>' +
        '<form id="userCreateForm" class="user-create-grid">' +
        '<label class="field"><span>Nombre</span><input name="name" type="text" required></label>' +
        '<label class="field"><span>Usuario</span><input name="username" type="text" required></label>' +
        '<label class="field"><span>Rol</span><select name="role"><option value="empleado">Empleado</option><option value="admin">Administrador</option></select></label>' +
        '<label class="field"><span>Contraseña</span><input name="password" type="password" required></label>' +
        '<button class="btn-primary" type="submit">Crear usuario</button></form>' +
        '<div class="mov-table-wrap"><table class="mov-table"><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Último ingreso</th><th>Acción</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
        '<div class="form-message" id="userMessage" role="alert"></div></article>';
    });
  }

  function createUser(form) {
    var message = byId('userMessage');
    apiPost(API.users, formToObject(form)).then(renderDashboard).catch(function (error) { showInlineError(message, error.message); });
  }

  function toggleUser(button) {
    fetchJson(API.users, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ id: button.getAttribute('data-user-toggle'), active: button.getAttribute('data-active') === '1' }) })
      .then(renderDashboard)
      .catch(function (error) { renderNotice('error', 'No se pudo actualizar el usuario', error.message); });
  }

  function loadStatus() {
    fetch(API.status, { headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data && data.ultima_sync) {
          dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Actualizado ' + escapeHtml(formatDate(data.ultima_sync, { withTime: true })) + '</span>';
        } else {
          dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Sistema activo</span>';
        }
      })
      .catch(function () { dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Estado no disponible</span>'; });
  }

  function formToObject(form) {
    var obj = {};
    Array.prototype.forEach.call(new FormData(form).entries(), function (entry) { obj[entry[0]] = entry[1]; });
    return obj;
  }

  function showInlineError(element, message) {
    if (!element) return;
    element.textContent = message;
    element.classList.add('is-error');
  }

  function bindEvents() {
    dom.form.addEventListener('submit', consultarExpediente);
    dom.clear.addEventListener('click', clearForm);
    dom.loginButton.addEventListener('click', renderLogin);
    dom.dashboardButton.addEventListener('click', renderDashboard);
    dom.logoutButton.addEventListener('click', logout);
    dom.numero.addEventListener('input', function (event) { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 10); });
    dom.letra.addEventListener('input', function (event) { event.target.value = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase(); });
    dom.ano.addEventListener('input', function (event) { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 4); });
    dom.result.addEventListener('submit', function (event) {
      if (event.target && event.target.id === 'authForm') { event.preventDefault(); submitAuth(event.target); }
      if (event.target && event.target.id === 'userCreateForm') { event.preventDefault(); createUser(event.target); }
    });
    dom.result.addEventListener('click', function (event) {
      if (event.target && event.target.id === 'loadMoreButton') loadMoreMovements();
      if (event.target && event.target.getAttribute('data-user-toggle')) toggleUser(event.target);
    });
  }

  if (!window.fetch || !window.FormData || !window.URLSearchParams) {
    dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Navegador no compatible</span>';
    renderNotice('error', 'Navegador no compatible', 'Actualizá el navegador para usar la consulta de expedientes.');
    return;
  }

  bindEvents();
  loadStatus();
  loadSession();
}());

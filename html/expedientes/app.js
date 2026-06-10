const API = {
  consulta: './api/consulta.php',
  status: './api/status.php',
  me: './api/me.php',
  setup: './api/setup.php',
  login: './api/login.php',
  logout: './api/logout.php',
  dashboard: './api/dashboard.php',
  users: './api/users.php',
};

const INITIAL_LIMIT = 12;

const ESTADOS = {
  I: { label: 'Ingresado', className: 'status-progress' },
  E: { label: 'En trámite', className: 'status-progress' },
  A: { label: 'Archivado', className: 'status-done' },
  X: { label: 'Terminado', className: 'status-done' },
  T: { label: 'Terminado', className: 'status-done' },
  C: { label: 'Cerrado', className: 'status-paused' },
  P: { label: 'Paralizado', className: 'status-paused' },
  N: { label: 'Anulado', className: 'status-cancelled' },
};

const state = {
  lastParams: null,
  expediente: null,
  movimientos: [],
  meta: null,
  session: { authenticated: false, user: null, setup_required: false },
};

const dom = {
  form: document.getElementById('searchForm'),
  numero: document.getElementById('numero'),
  letra: document.getElementById('letra'),
  ano: document.getElementById('ano'),
  button: document.getElementById('searchButton'),
  clear: document.getElementById('clearButton'),
  message: document.getElementById('formMessage'),
  result: document.getElementById('resultPanel'),
  status: document.getElementById('syncStatus'),
  loginButton: document.getElementById('loginButton'),
  dashboardButton: document.getElementById('dashboardButton'),
  logoutButton: document.getElementById('logoutButton'),
};

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function normalizeText(value) {
  const clean = String(value ?? '').replace(/\r/g, '').trim();
  return clean === '' || clean === '0' || clean === '0000-00-00 00:00:00' ? '—' : clean;
}

function isEmptyDate(value) {
  return !value || String(value).startsWith('1900') || value === '0000-00-00 00:00:00';
}

function parseDate(value) {
  if (isEmptyDate(value)) return null;
  const parsed = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function formatDate(value, options = {}) {
  const parsed = parseDate(value);
  if (!parsed) return '—';
  const date = parsed.toLocaleDateString('es-AR', { day: '2-digit', month: options.short ? '2-digit' : 'long', year: 'numeric' });
  if (!options.withTime) return date;
  return `${date} · ${parsed.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}`;
}

function statusInfo(code) {
  const key = String(code ?? '').trim().toUpperCase();
  return ESTADOS[key] || { label: key || 'Sin estado', className: 'status-neutral' };
}

function setFormMessage(message) {
  dom.message.textContent = message || '';
  dom.message.classList.toggle('is-error', Boolean(message));
}

function setLoading(isLoading) {
  dom.button.disabled = isLoading;
  dom.button.querySelector('span:last-child').textContent = isLoading ? 'Consultando…' : 'Consultar expediente';
}

function renderLoading(label = 'Buscando expediente en el sistema…') {
  dom.result.innerHTML = `
    <article class="loading-card">
      <span class="spinner" aria-hidden="true"></span>
      <span>${escapeHtml(label)}</span>
    </article>`;
}

function renderNotice(type, title, message, detail = '') {
  const iconClass = type === 'error' ? 'status-cancelled' : 'status-progress';
  dom.result.innerHTML = `
    <article class="notice-card">
      <span class="notice-icon ${iconClass}" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M11 7h2v7h-2V7Zm0 9h2v2h-2v-2Zm1-14a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18.2A8.2 8.2 0 1 1 12 3.8a8.2 8.2 0 0 1 0 16.4Z"/></svg>
      </span>
      <p class="eyebrow">${type === 'error' ? 'Error' : 'Sin resultados'}</p>
      <h2>${escapeHtml(title)}</h2>
      <p>${escapeHtml(message)}</p>
      ${detail ? `<div class="empty-tips"><span>${escapeHtml(detail)}</span></div>` : ''}
    </article>`;
}

function getSearchParams() {
  const numero = dom.numero.value.trim();
  const letra = dom.letra.value.trim().toUpperCase();
  const ano = dom.ano.value.trim();

  if (!numero || !ano) return { error: 'Completá número y año para realizar la consulta.' };
  if (!/^\d{1,10}$/.test(numero)) return { error: 'El número de expediente debe contener solo dígitos.' };
  if (letra && !/^[A-Z]$/.test(letra)) return { error: 'La letra debe ser una única letra.' };
  if (!/^\d{4}$/.test(ano)) return { error: 'El año debe tener 4 dígitos.' };

  const year = Number.parseInt(ano, 10);
  const currentYear = new Date().getFullYear() + 1;
  if (year < 1990 || year > currentYear) return { error: `El año debe estar entre 1990 y ${currentYear}.` };

  return { numero, letra, ano };
}

function buildQuery(params, offset = 0) {
  const query = new URLSearchParams({ numero: params.numero, ano: params.ano, limit: String(INITIAL_LIMIT), offset: String(offset) });
  if (params.letra) query.set('letra', params.letra);
  return query;
}

async function consultarExpediente(event) {
  event.preventDefault();
  setFormMessage('');

  const params = getSearchParams();
  if (params.error) {
    setFormMessage(params.error);
    return;
  }

  setLoading(true);
  renderLoading();

  try {
    const response = await fetch(`${API.consulta}?${buildQuery(params, 0).toString()}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json();

    if (!response.ok || payload?.error) {
      renderNotice('error', 'No se pudo realizar la consulta', payload?.error || statusToMessage(response.status));
      return;
    }

    if (!payload.expediente) {
      const label = `N° ${params.numero}${params.letra ? '/' + params.letra : ''} · ${params.ano}`;
      renderNotice('empty', 'Expediente no encontrado', 'Verificá los datos ingresados o consultá en Mesa de Entradas.', label);
      return;
    }

    state.lastParams = params;
    state.expediente = payload.expediente;
    state.movimientos = payload.movimientos || [];
    state.meta = payload.meta || null;
    renderExpediente(state.expediente, state.movimientos, state.meta);
  } catch (error) {
    console.error(error);
    renderNotice('error', 'Servicio no disponible', 'No se pudo conectar con la API. Intentá nuevamente más tarde.');
  } finally {
    setLoading(false);
  }
}

function statusToMessage(status) {
  if (status === 400) return 'Los datos enviados no tienen el formato esperado.';
  if (status === 429) return 'Se realizaron demasiadas consultas. Esperá un momento y volvé a intentar.';
  if (status >= 500) return 'El servidor no pudo procesar la consulta en este momento.';
  return 'Ocurrió un error al consultar el expediente.';
}

function expedienteId(expediente) {
  const letra = normalizeText(expediente.LETRA);
  const letraPart = letra !== '—' ? `/${letra}` : '';
  return `N° ${normalizeText(expediente.NUMERO)}${letraPart} · ${normalizeText(expediente.ANO)}`;
}

function renderExpediente(expediente, movimientos, meta = null) {
  const stateInfo = statusInfo(expediente.ESTADO);
  const sectorActual = normalizeText(expediente.SECTACTUAL_NOMBRE || expediente.SECTACTUAL);
  const sectorInicia = normalizeText(expediente.SECTORINICIA_NOMBRE || expediente.SECTORINICIA);
  const motivo = normalizeText(expediente.MOTIVO);
  const iniciador = normalizeText(expediente.EXTERNOINICIA || expediente.INICIADOR);
  const ultimaActualizacion = expediente.updated_at || expediente.FECHACARGA;

  dom.result.innerHTML = `
    <article class="exp-card">
      <header class="exp-head">
        <div class="exp-title">
          <small>Expediente</small>
          <h2>${escapeHtml(expedienteId(expediente))}</h2>
          <p class="exp-subtitle">Información pública del trámite y recorrido registrado por sectores municipales.</p>
        </div>
        <span class="status-badge ${stateInfo.className}">${escapeHtml(stateInfo.label)}</span>
      </header>
      ${Number(expediente.ANULADO) === 1 ? '<div class="annulled">⚠ Expediente anulado</div>' : ''}
      <section class="quick-sector" aria-label="Sector actual">
        <span class="sector-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 3.5 9.35l.9 1.2L6 9.35V20h12V9.35l1.6 1.2.9-1.2L12 3Zm4.5 15.5h-9V8.22L12 4.86l4.5 3.36V18.5ZM10 11h4v7h-4v-7Z"/></svg></span>
        <div><span>Sector / área actual</span><strong>${escapeHtml(sectorActual)}</strong></div>
      </section>
      <div class="exp-body">
        <section class="data-grid" aria-label="Datos principales del expediente">
          ${dataItem('Fecha de inicio', formatDate(expediente.FECHAINICIO))}
          ${dataItem('Tipo de expediente', normalizeText(expediente.TIPOEXPEDIENTE))}
          ${dataItem('Iniciado por', iniciador)}
          ${dataItem('Sector iniciador', sectorInicia)}
          ${dataItem('Destino informado', normalizeText(expediente.DESTINO || expediente.SECTORDESTINO))}
          ${dataItem('Última actualización', formatDate(ultimaActualizacion, { withTime: true }))}
        </section>
        ${motivo !== '—' ? `<section class="topic-box" aria-label="Motivo o asunto"><span>Motivo / asunto</span><p>${escapeHtml(motivo)}</p></section>` : ''}
        <section class="timeline-section" aria-labelledby="timelineTitle">
          <div class="section-title">
            <h3 id="timelineTitle">Historial de movimientos</h3>
            <span class="count-pill">${movementCountLabel(movimientos, meta)}</span>
          </div>
          ${renderMovementsTable(movimientos)}
          ${renderLoadMore(meta)}
        </section>
      </div>
    </article>`;
}

function dataItem(label, value) {
  return `<div class="data-item"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`;
}

function movementCountLabel(movimientos, meta) {
  const total = Number(meta?.movimientos_total ?? movimientos.length);
  const shown = movimientos.length;
  if (total > shown) return `${shown} de ${total} movimientos`;
  return `${total} ${total === 1 ? 'movimiento' : 'movimientos'}`;
}

function renderLoadMore(meta) {
  if (!meta?.has_more) return '';
  return `<div class="load-more-wrap"><button class="btn-load-more" id="loadMoreButton" type="button">Ver más movimientos</button></div>`;
}

function renderMovementsTable(movimientos) {
  if (!movimientos.length) {
    return `<article class="notice-card"><h2>Sin movimientos registrados</h2><p>El expediente existe, pero todavía no hay movimientos sincronizados para mostrar.</p></article>`;
  }
  return `
    <div class="mov-table-wrap">
      <table class="mov-table">
        <thead><tr><th>Fecha</th><th>Sector</th><th>Proveniente</th><th>Estado</th><th>Fojas</th><th>Observaciones</th></tr></thead>
        <tbody>${movimientos.map((mov, index) => renderMovementRow(mov, index === 0)).join('')}</tbody>
      </table>
    </div>`;
}

function renderMovementRow(movimiento, isCurrent) {
  const sector = normalizeText(movimiento.SECTORACTUAL_NOMBRE || movimiento.SECTORACTUAL);
  const proveniente = normalizeText(movimiento.SECTORPROVENIENTE);
  const estado = statusInfo(movimiento.ESTADOACTUAL).label;
  const observaciones = normalizeText(movimiento.OBSERVACIONES);
  const fojas = normalizeText(movimiento.FOJAS);
  return `
    <tr class="${isCurrent ? 'is-current' : ''}">
      <td>${escapeHtml(formatDate(movimiento.FECHAHORA, { withTime: true }))}</td>
      <td><strong>${escapeHtml(sector)}</strong>${isCurrent ? '<span class="current-tag">Actual</span>' : ''}</td>
      <td>${escapeHtml(proveniente)}</td>
      <td>${escapeHtml(estado)}</td>
      <td>${escapeHtml(fojas)}</td>
      <td>${escapeHtml(observaciones)}</td>
    </tr>`;
}

async function loadMoreMovements() {
  if (!state.lastParams || !state.meta?.has_more) return;
  const button = document.getElementById('loadMoreButton');
  if (button) {
    button.disabled = true;
    button.textContent = 'Cargando movimientos…';
  }

  try {
    const response = await fetch(`${API.consulta}?${buildQuery(state.lastParams, state.movimientos.length).toString()}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || payload?.error) throw new Error(payload?.error || statusToMessage(response.status));
    state.movimientos = state.movimientos.concat(payload.movimientos || []);
    state.meta = payload.meta || state.meta;
    renderExpediente(state.expediente, state.movimientos, state.meta);
  } catch (error) {
    console.error(error);
    if (button) {
      button.disabled = false;
      button.textContent = 'No se pudo cargar. Reintentar';
    }
  }
}

function clearForm() {
  dom.form.reset();
  setFormMessage('');
  state.lastParams = null;
  state.expediente = null;
  state.movimientos = [];
  state.meta = null;
  dom.result.innerHTML = `<article class="empty-state"><span class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3.5h7.25L19 8.25V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20V5a1.5 1.5 0 0 1 2-1.5Zm7 1.8V8.5h3.2L14 5.3ZM7 5v15h10.5V10H12.5V5H7Zm2 8h6v1.5H9V13Zm0 3h6v1.5H9V16Zm0-6h3v1.5H9V10Z"/></svg></span><p class="eyebrow">Inicio</p><h2>Listo para consultar</h2><p>Completá número y año para ver el estado del expediente, su área actual y el recorrido registrado por SIGAP.</p></article>`;
  dom.numero.focus();
}

async function apiPost(url, data = {}) {
  const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(data) });
  const payload = await response.json();
  if (!response.ok || payload?.error) throw new Error(payload?.error || 'Error de servidor.');
  return payload;
}

async function loadSession() {
  try {
    const response = await fetch(API.me, { headers: { Accept: 'application/json' } });
    state.session = await response.json();
  } catch (_) {
    state.session = { authenticated: false, user: null, setup_required: false };
  }
  updateHeaderAuth();
}

function updateHeaderAuth() {
  const logged = Boolean(state.session.authenticated);
  dom.loginButton.classList.toggle('is-hidden', logged);
  dom.dashboardButton.classList.toggle('is-hidden', !logged);
  dom.logoutButton.classList.toggle('is-hidden', !logged);
  if (logged) dom.dashboardButton.textContent = `Dashboard · ${state.session.user.name || state.session.user.username}`;
}

function renderLogin() {
  const setup = Boolean(state.session.setup_required);
  dom.result.innerHTML = `
    <section class="admin-shell">
      <article class="admin-card">
        <p class="eyebrow">${setup ? 'Configuración inicial' : 'Acceso interno'}</p>
        <h2>${setup ? 'Crear primer administrador' : 'Ingreso de empleados'}</h2>
        <p>${setup ? 'No hay usuarios configurados. Creá el primer administrador local.' : 'Ingresá con tu usuario para ver dashboard y gestión de usuarios.'}</p>
        <form id="authForm" class="admin-form">
          ${setup ? '<label class="field"><span>Nombre completo</span><input name="name" type="text" autocomplete="name" required></label>' : ''}
          <label class="field"><span>Usuario</span><input name="username" type="text" autocomplete="username" required></label>
          <label class="field"><span>Contraseña</span><input name="password" type="password" autocomplete="current-password" required></label>
          <button class="btn-primary" type="submit">${setup ? 'Crear administrador' : 'Ingresar'}</button>
          <div class="form-message" id="authMessage" role="alert"></div>
        </form>
      </article>
    </section>`;
}

async function submitAuth(form) {
  const data = Object.fromEntries(new FormData(form).entries());
  const message = document.getElementById('authMessage');
  try {
    await apiPost(state.session.setup_required ? API.setup : API.login, data);
    await loadSession();
    await renderDashboard();
  } catch (error) {
    message.textContent = error.message;
    message.classList.add('is-error');
  }
}

async function logout() {
  try { await apiPost(API.logout); } catch (_) {}
  await loadSession();
  clearForm();
}

async function renderDashboard() {
  if (!state.session.authenticated) {
    renderLogin();
    return;
  }
  renderLoading('Cargando dashboard…');
  try {
    const response = await fetch(API.dashboard, { headers: { Accept: 'application/json' } });
    const dashboard = await response.json();
    if (!response.ok || dashboard?.error) throw new Error(dashboard?.error || 'No se pudo cargar el dashboard.');
    const usersHtml = state.session.user.role === 'admin' ? await renderUsersSection() : '';
    dom.result.innerHTML = `
      <section class="admin-shell">
        <article class="admin-card">
          <p class="eyebrow">Panel interno</p>
          <h2>Dashboard municipal</h2>
          <p>Resumen operativo de expedientes sincronizados. Última actualización: ${escapeHtml(formatDate(dashboard.ultima_sync, { withTime: true }))}</p>
          <div class="stats-grid">
            ${statCard('Expedientes', dashboard.totals.expedientes)}
            ${statCard('Movimientos', dashboard.totals.movimientos)}
            ${statCard('Sectores vigentes', dashboard.totals.sectores)}
          </div>
          <div class="dashboard-grid">
            ${miniTable('Estados', dashboard.by_estado, 'ESTADO')}
            ${miniTable('Sectores con más expedientes', dashboard.by_sector, 'sector')}
          </div>
        </article>
        ${usersHtml}
      </section>`;
  } catch (error) {
    renderNotice('error', 'No se pudo abrir el dashboard', error.message);
  }
}

function statCard(label, value) {
  return `<div class="stat-card"><span>${escapeHtml(label)}</span><strong>${Number(value || 0).toLocaleString('es-AR')}</strong></div>`;
}

function miniTable(title, rows, labelKey) {
  return `<div class="mini-table"><h3>${escapeHtml(title)}</h3><table><tbody>${(rows || []).map(row => `<tr><td>${escapeHtml(normalizeText(row[labelKey]))}</td><td>${Number(row.total || 0).toLocaleString('es-AR')}</td></tr>`).join('')}</tbody></table></div>`;
}

async function renderUsersSection() {
  const response = await fetch(API.users, { headers: { Accept: 'application/json' } });
  const payload = await response.json();
  const users = payload.users || [];
  return `
    <article class="admin-card">
      <p class="eyebrow">Administración</p>
      <h2>Gestión de usuarios</h2>
      <form id="userCreateForm" class="user-create-grid">
        <label class="field"><span>Nombre</span><input name="name" type="text" required></label>
        <label class="field"><span>Usuario</span><input name="username" type="text" required></label>
        <label class="field"><span>Rol</span><select name="role"><option value="empleado">Empleado</option><option value="admin">Administrador</option></select></label>
        <label class="field"><span>Contraseña</span><input name="password" type="password" required></label>
        <button class="btn-primary" type="submit">Crear usuario</button>
      </form>
      <div class="mov-table-wrap"><table class="mov-table"><thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Último ingreso</th><th>Acción</th></tr></thead><tbody>
        ${users.map(user => `<tr><td>${escapeHtml(user.username)}</td><td>${escapeHtml(user.name)}</td><td>${escapeHtml(user.role)}</td><td>${user.active ? 'Activo' : 'Inactivo'}</td><td>${escapeHtml(formatDate(user.last_login_at, { withTime: true }))}</td><td><button class="table-action" data-user-toggle="${escapeHtml(user.id)}" data-active="${user.active ? '0' : '1'}">${user.active ? 'Desactivar' : 'Activar'}</button></td></tr>`).join('')}
      </tbody></table></div>
      <div class="form-message" id="userMessage" role="alert"></div>
    </article>`;
}

async function createUser(form) {
  const message = document.getElementById('userMessage');
  try {
    await apiPost(API.users, Object.fromEntries(new FormData(form).entries()));
    await renderDashboard();
  } catch (error) {
    message.textContent = error.message;
    message.classList.add('is-error');
  }
}

async function toggleUser(button) {
  await fetch(API.users, { method: 'PATCH', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ id: button.dataset.userToggle, active: button.dataset.active === '1' }) });
  await renderDashboard();
}

async function loadStatus() {
  try {
    const response = await fetch(API.status, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (data?.ultima_sync) {
      dom.status.innerHTML = `<span class="pulse" aria-hidden="true"></span><span>Actualizado ${escapeHtml(formatDate(data.ultima_sync, { withTime: true }))}</span>`;
      return;
    }
    dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Sistema activo</span>';
  } catch (_) {
    dom.status.innerHTML = '<span class="pulse" aria-hidden="true"></span><span>Estado no disponible</span>';
  }
}

function bindEvents() {
  dom.form.addEventListener('submit', consultarExpediente);
  dom.clear.addEventListener('click', clearForm);
  dom.loginButton.addEventListener('click', renderLogin);
  dom.dashboardButton.addEventListener('click', renderDashboard);
  dom.logoutButton.addEventListener('click', logout);
  dom.numero.addEventListener('input', (event) => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 10); });
  dom.letra.addEventListener('input', (event) => { event.target.value = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase(); });
  dom.ano.addEventListener('input', (event) => { event.target.value = event.target.value.replace(/\D/g, '').slice(0, 4); });
  dom.result.addEventListener('submit', (event) => {
    if (event.target?.id === 'authForm') { event.preventDefault(); submitAuth(event.target); }
    if (event.target?.id === 'userCreateForm') { event.preventDefault(); createUser(event.target); }
  });
  dom.result.addEventListener('click', (event) => {
    if (event.target?.id === 'loadMoreButton') loadMoreMovements();
    if (event.target?.dataset?.userToggle) toggleUser(event.target);
  });
}

bindEvents();
loadStatus();
loadSession();

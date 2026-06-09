const API = {
  consulta: './api/consulta.php',
  status: './api/status.php',
};

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

const dom = {
  form: document.getElementById('searchForm'),
  smart: document.getElementById('smartSearch'),
  numero: document.getElementById('numero'),
  letra: document.getElementById('letra'),
  ano: document.getElementById('ano'),
  button: document.getElementById('searchButton'),
  clear: document.getElementById('clearButton'),
  message: document.getElementById('formMessage'),
  result: document.getElementById('resultPanel'),
  status: document.getElementById('syncStatus'),
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

function renderLoading() {
  dom.result.innerHTML = `
    <article class="loading-card">
      <span class="spinner" aria-hidden="true"></span>
      <span>Buscando expediente en el sistema…</span>
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

function parseSmartSearch(value) {
  const raw = value.trim().toUpperCase();
  if (!raw) return;

  const full = raw.match(/^(\d{1,10})(?:\s*[\/\-\s]\s*([A-Z])\s*)?(?:[\/\-\s]+)(\d{4})$/);
  if (full) {
    dom.numero.value = full[1];
    if (full[2]) dom.letra.value = full[2];
    dom.ano.value = full[3];
    return;
  }

  const numberOnly = raw.match(/^\d{1,10}$/);
  if (numberOnly) {
    dom.numero.value = raw;
  }
}

function getSearchParams() {
  parseSmartSearch(dom.smart.value);
  const numero = dom.numero.value.trim();
  const letra = dom.letra.value.trim().toUpperCase();
  const ano = dom.ano.value.trim();

  if (!numero || !ano) {
    return { error: 'Completá número y año para realizar la consulta.' };
  }
  if (!/^\d{1,10}$/.test(numero)) {
    return { error: 'El número de expediente debe contener solo dígitos.' };
  }
  if (letra && !/^[A-Z]$/.test(letra)) {
    return { error: 'La letra debe ser una única letra.' };
  }
  if (!/^\d{4}$/.test(ano)) {
    return { error: 'El año debe tener 4 dígitos.' };
  }

  const year = Number.parseInt(ano, 10);
  const currentYear = new Date().getFullYear() + 1;
  if (year < 1990 || year > currentYear) {
    return { error: `El año debe estar entre 1990 y ${currentYear}.` };
  }

  return { numero, letra, ano };
}

async function consultarExpediente(event) {
  event.preventDefault();
  setFormMessage('');

  const params = getSearchParams();
  if (params.error) {
    setFormMessage(params.error);
    return;
  }

  const query = new URLSearchParams({ numero: params.numero, ano: params.ano });
  if (params.letra) query.set('letra', params.letra);

  setLoading(true);
  renderLoading();

  try {
    const response = await fetch(`${API.consulta}?${query.toString()}`, { headers: { Accept: 'application/json' } });
    let payload = null;
    try {
      payload = await response.json();
    } catch (_) {
      throw new Error('La respuesta del servidor no es JSON válido.');
    }

    if (!response.ok || payload?.error) {
      const friendly = payload?.error || statusToMessage(response.status);
      renderNotice('error', 'No se pudo realizar la consulta', friendly);
      return;
    }

    if (!payload.expediente) {
      const label = `N° ${params.numero}${params.letra ? '/' + params.letra : ''} · ${params.ano}`;
      renderNotice('empty', 'Expediente no encontrado', 'Verificá los datos ingresados o consultá en Mesa de Entradas.', label);
      return;
    }

    renderExpediente(payload.expediente, payload.movimientos || []);
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

function renderExpediente(expediente, movimientos) {
  const state = statusInfo(expediente.ESTADO);
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
        <span class="status-badge ${state.className}">${escapeHtml(state.label)}</span>
      </header>

      ${Number(expediente.ANULADO) === 1 ? '<div class="annulled">⚠ Expediente anulado</div>' : ''}

      <section class="quick-sector" aria-label="Sector actual">
        <span class="sector-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 3 3.5 9.35l.9 1.2L6 9.35V20h12V9.35l1.6 1.2.9-1.2L12 3Zm4.5 15.5h-9V8.22L12 4.86l4.5 3.36V18.5ZM10 11h4v7h-4v-7Z"/></svg>
        </span>
        <div>
          <span>Sector / área actual</span>
          <strong>${escapeHtml(sectorActual)}</strong>
        </div>
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

        ${motivo !== '—' ? `
          <section class="topic-box" aria-label="Motivo o asunto">
            <span>Motivo / asunto</span>
            <p>${escapeHtml(motivo)}</p>
          </section>` : ''}

        <section class="timeline-section" aria-labelledby="timelineTitle">
          <div class="section-title">
            <h3 id="timelineTitle">Historial de movimientos</h3>
            <span class="count-pill">${movimientos.length} ${movimientos.length === 1 ? 'movimiento' : 'movimientos'}</span>
          </div>
          ${renderTimeline(movimientos)}
        </section>
      </div>
    </article>`;
}

function dataItem(label, value) {
  return `<div class="data-item"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`;
}

function renderTimeline(movimientos) {
  if (!movimientos.length) {
    return `
      <article class="notice-card">
        <span class="notice-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 4h16v2H4V4Zm0 7h16v2H4v-2Zm0 7h10v2H4v-2Z"/></svg>
        </span>
        <h2>Sin movimientos registrados</h2>
        <p>El expediente existe, pero todavía no hay movimientos sincronizados para mostrar.</p>
      </article>`;
  }

  return `
    <div class="timeline">
      ${movimientos.map((mov, index) => renderTimelineItem(mov, index === 0)).join('')}
    </div>`;
}

function renderTimelineItem(movimiento, isCurrent) {
  const sector = normalizeText(movimiento.SECTORACTUAL_NOMBRE || movimiento.SECTORACTUAL);
  const proveniente = normalizeText(movimiento.SECTORPROVENIENTE);
  const estado = statusInfo(movimiento.ESTADOACTUAL).label;
  const observaciones = normalizeText(movimiento.OBSERVACIONES);
  const recepcion = formatDate(movimiento.FECHARECEPCION, { withTime: true });
  const fojas = normalizeText(movimiento.FOJAS);

  return `
    <article class="timeline-item ${isCurrent ? 'is-current' : ''}">
      <div class="timeline-dot" aria-hidden="true">${isCurrent ? '●' : '○'}</div>
      <div class="timeline-card">
        <div class="timeline-top">
          <div class="timeline-sector">
            ${escapeHtml(sector)}
            ${isCurrent ? '<span class="current-tag">Actual</span>' : ''}
          </div>
          <div class="timeline-date">${escapeHtml(formatDate(movimiento.FECHAHORA, { withTime: true }))}</div>
        </div>
        <div class="timeline-meta">
          <span>Desde: ${escapeHtml(proveniente)}</span>
          <span>Estado: ${escapeHtml(estado)}</span>
          <span>Fojas: ${escapeHtml(fojas)}</span>
          ${recepcion !== '—' ? `<span>Recepción: ${escapeHtml(recepcion)}</span>` : ''}
        </div>
        ${observaciones !== '—' ? `<p class="timeline-obs">${escapeHtml(observaciones)}</p>` : ''}
      </div>
    </article>`;
}

function clearForm() {
  dom.form.reset();
  setFormMessage('');
  dom.result.innerHTML = `
    <article class="empty-state">
      <span class="empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M7 3.5h7.25L19 8.25V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20V5a1.5 1.5 0 0 1 2-1.5Zm7 1.8V8.5h3.2L14 5.3ZM7 5v15h10.5V10H12.5V5H7Zm2 8h6v1.5H9V13Zm0 3h6v1.5H9V16Zm0-6h3v1.5H9V10Z"/></svg>
      </span>
      <p class="eyebrow">Inicio</p>
      <h2>Listo para consultar</h2>
      <p>Completá número y año para ver el estado del expediente, su área actual y el recorrido registrado por SIGAP.</p>
    </article>`;
  dom.smart.focus();
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
  dom.smart.addEventListener('input', (event) => parseSmartSearch(event.target.value));
  dom.numero.addEventListener('input', (event) => {
    event.target.value = event.target.value.replace(/\D/g, '').slice(0, 10);
  });
  dom.letra.addEventListener('input', (event) => {
    event.target.value = event.target.value.replace(/[^a-zA-Z]/g, '').slice(0, 1).toUpperCase();
  });
  dom.ano.addEventListener('input', (event) => {
    event.target.value = event.target.value.replace(/\D/g, '').slice(0, 4);
  });
}

bindEvents();
loadStatus();

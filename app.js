(() => {
  'use strict';

  const DEP = window.Departamentos;
  const $ = (s, c = document) => c.querySelector(s);
  const COP = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });
  const MILES = new Intl.NumberFormat('es-CO');
  const PCT = (t) => `${(t * 100).toLocaleString('es-CO', { maximumFractionDigits: 1 })} %`;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const titulo = (s) => String(s || '').toLowerCase().replace(/(^|\s)\S/g, m => m.toUpperCase());
  const norm = (s) => String(s || '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

  const form = $('#form-consulta');
  const inPlaca = $('#placa');
  const inDoc = $('#documento');
  const selTipo = $('#tipo');
  const errorBox = $('#error');
  const btn = $('#consultar');
  const res = $('#resultado');

  // Respuesta de la consulta actual (el cálculo lo hace el servidor)
  let veh = null;
  let calculo = null;
  let meta = null;

  // ------------------------------------------------------------
  // Configuración (tipos de documento, modo demo)
  // ------------------------------------------------------------
  fetch('consulta.php?accion=config')
    .then(r => r.json())
    .then(cfg => {
      selTipo.innerHTML = Object.entries(cfg.tipos_documento)
        .map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('');
      if (cfg.demo) $('#banner-demo').hidden = false;
    })
    .catch(() => {
      selTipo.innerHTML = '<option value="C">Cédula de ciudadanía</option>';
      mostrarError('No se pudo conectar con el servidor. Recarga la página e inténtalo de nuevo.');
    });

  inPlaca.addEventListener('input', () => {
    inPlaca.value = inPlaca.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);
  });
  inDoc.addEventListener('input', () => {
    inDoc.value = inDoc.value.replace(/[^0-9A-Za-z]/g, '').slice(0, 15);
  });

  // ------------------------------------------------------------
  // Consulta
  // ------------------------------------------------------------
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorBox.hidden = true;

    const placa = inPlaca.value.trim();
    const documento = inDoc.value.trim();
    if (!/^[A-Z0-9]{5,6}$/.test(placa)) return mostrarError('Escribe la placa sin espacios ni guiones, por ejemplo ABC123.');
    if (documento.length < 4) return mostrarError('Escribe el número de documento del propietario.');
    if (!$('#acepta').checked) return mostrarError('Debes autorizar el tratamiento de datos para hacer la consulta.');

    cargando(true);
    try {
      const r = await fetch('consulta.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ placa, documento, tipo: selTipo.value, acepta_datos: true }),
      });
      const data = await r.json().catch(() => ({ ok: false, error: 'El servidor respondió con un formato inesperado.' }));
      if (!r.ok || !data.ok) throw new Error(data.error || 'No se pudo completar la consulta.');

      veh = data.vehiculo;
      veh.documento = data.documento;
      calculo = data.calculo;
      meta = data.meta;
      pintar();
    } catch (ex) {
      mostrarError(ex.message);
    } finally {
      cargando(false);
    }
  });

  // ------------------------------------------------------------
  // Render
  // ------------------------------------------------------------
  function pintar() {
    const dep = DEP.detectar(veh.organismo);
    res.innerHTML = htmlVehiculo(dep) + htmlAvaluo();
    res.hidden = false;
  }

  function dato(etq, valor) {
    return valor ? `<div><dt>${esc(etq)}</dt><dd>${valor}</dd></div>` : '';
  }

  function siNo(v) {
    if (!v) return null;
    const s = norm(v);
    return s === 'SI' ? '<span class="estado-malo">Sí</span>' : s === 'NO' ? 'No' : esc(v);
  }

  function htmlVehiculo(dep) {
    const v = veh;
    const estado = v.estado ? `<span class="pill ${norm(v.estado) === 'ACTIVO' ? 'ok' : 'malo'}">${esc(titulo(v.estado))}</span>` : '';
    return `
      <section class="tarjeta vehiculo">
        <div class="veh-cab">
          <div class="placa-chica" aria-label="Placa">${esc(v.placa)}</div>
          <div class="veh-nombre">
            <span class="ficha-marca">${esc(v.marca)}</span>
            <h2 class="ficha-linea">${esc(v.linea)} <span class="veh-modelo">${esc(v.modelo || '')}</span></h2>
          </div>
          ${estado}
        </div>
        <dl class="datos">
          ${dato('Clase', esc(titulo(v.clase)))}
          ${dato('Carrocería', esc(titulo(v.carroceria)))}
          ${dato('Servicio', esc(titulo(v.servicio)))}
          ${dato('Color', esc(titulo(v.color)))}
          ${dato('Cilindraje', v.cilindraje ? `${MILES.format(v.cilindraje)} c.c.` : '')}
          ${dato('Combustible', esc(titulo(v.combustible)))}
          ${dato('Pasajeros', esc(v.pasajeros))}
          ${dato('Puertas', esc(v.puertas))}
          ${dato('Matrícula', v.fecha_matricula ? esc(fechaLarga(v.fecha_matricula)) : '')}
          ${dato('Organismo de tránsito', esc(titulo(v.organismo)))}
          ${dato('Departamento', dep ? esc(dep.nombre) : '')}
          ${dato('Prenda', siNo(v.prendas))}
          ${dato('Gravámenes', siNo(v.gravamenes))}
          ${dato('Propietario', esc(v.documento))}
        </dl>
      </section>`;
  }

  function htmlAvaluo() {
    const cuerpo = calculo
      ? htmlCalculo(calculo)
      : `<p class="aviso">No encontramos la marca <strong>${esc(veh.marca)}</strong> en la tabla oficial de avalúos. Si es una moto de hasta 125 c.c., no aparece porque no paga este impuesto.</p>`;

    return `
      <section class="tarjeta avaluo">
        <h2 class="tarjeta-titulo">Avalúo e impuesto ${meta.anio_fiscal}</h2>
        ${cuerpo}
      </section>`;
  }

  function htmlCalculo(c) {
    let cifras = '';
    const anioTxt = c.modelo === meta.min_anio ? `${c.modelo} o anterior` : c.modelo;

    switch (c.estado) {
      case 'nuevo':
        cifras = `<p class="aviso">Los vehículos modelo ${esc(veh.modelo)} no están en la tabla ${meta.anio_fiscal}: el primer año el impuesto se liquida con el valor de la factura de compra, proporcional a los meses que quedan del año.</p>`;
        break;
      case 'sin_valor':
        cifras = `<p class="aviso">Esta versión no tiene avalúo para el modelo ${esc(anioTxt)} en la tabla oficial.</p>`;
        break;
      case 'ilegible':
        cifras = `<p class="aviso">En la tabla oficial este avalúo aparece impreso como <strong>${esc(c.bruto)}</strong> (notación científica) y no se puede leer la cifra exacta. Supera los mil millones de pesos, así que la tarifa es 3,5 %. Consulta el valor exacto en tu secretaría de hacienda.</p>`;
        break;
      case 'publico':
      case 'oficial':
        cifras = `
          ${htmlCifras(c, anioTxt, null)}
          <p class="aviso">Este vehículo es de servicio <strong>${c.estado === 'publico' ? 'público' : 'oficial'}</strong>. ${c.estado === 'publico'
            ? 'El impuesto de los vehículos de servicio público no lo cobra el departamento sino el municipio donde está matriculado, con sus propias tarifas.'
            : 'Los vehículos oficiales generalmente no pagan este impuesto.'} Confírmalo en tu secretaría de hacienda o tránsito.</p>`;
        break;
      case 'exento':
        cifras = `${htmlCifras(c, anioTxt, 'No paga')}
          <p class="aviso">Las motos y motocarros de hasta 125 c.c. no pagan impuesto vehicular (Ley 488 de 1998).</p>`;
        break;
      default:
        cifras = `
          ${htmlCifras(c, anioTxt, COP.format(c.impuesto))}
          ${c.esMoto
            ? `<p class="ayuda-tarifa">Las motos de más de 125 c.c. pagan una tarifa fija de ${PCT(meta.tarifa_motos)}.</p>`
            : `<div class="escala" aria-label="Rango de tarifa">
                <div class="escala-barra">
                  ${meta.tarifas.map((t, i) => `<span class="tramo${i === c.tramo ? ' activo' : ''}">${PCT(t)}</span>`).join('')}
                </div>
                <div class="escala-marcas" aria-hidden="true"><span></span><span>$57,3 M</span><span>$129 M</span></div>
              </div>`}
          <table class="desglose">
            <tr><th>Avalúo comercial modelo ${esc(anioTxt)}</th><td>${COP.format(c.avaluo)}</td></tr>
            <tr><th>Tarifa${c.topado ? ' (tope eléctricos)' : ''}</th><td>${PCT(c.tarifa)}</td></tr>
            <tr class="total"><th>Impuesto ${meta.anio_fiscal}</th><td>${COP.format(c.impuesto)}</td></tr>
          </table>`;
    }

    return `
      ${cifras}
      ${c.notas.length ? `<ul class="notas-lista">${c.notas.map(n => `<li>${esc(n)}</li>`).join('')}</ul>` : ''}`;
  }

  function htmlCifras(c, anioTxt, impuestoTxt) {
    return `
      <div class="cifras">
        <div class="cifra">
          <span class="cifra-etq">Avalúo modelo ${esc(anioTxt)}</span>
          <span class="cifra-val">${c.avaluo != null ? COP.format(c.avaluo) : '—'}</span>
        </div>
        ${impuestoTxt ? `
        <div class="cifra placa">
          <span class="cifra-etq">Impuesto ${meta.anio_fiscal}</span>
          <span class="cifra-val">${esc(impuestoTxt)}</span>
        </div>` : ''}
      </div>`;
  }

  // ------------------------------------------------------------
  // Utilidades
  // ------------------------------------------------------------
  function fechaLarga(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    if (!y) return iso;
    return new Date(y, m - 1, d).toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function mostrarError(msg) {
    errorBox.textContent = msg;
    errorBox.hidden = false;
  }

  function cargando(on) {
    btn.disabled = on;
    btn.classList.toggle('cargando', on);
    $('.etq', btn).textContent = on ? 'Consultando…' : 'Consultar';
  }
})();

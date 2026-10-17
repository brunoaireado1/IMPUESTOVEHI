/**
 * Deduce el departamento a partir del organismo de tránsito del vehículo
 * y da el portal oficial donde se paga el impuesto.
 *
 * Verifica las URLs antes de publicar: apuntan al sitio principal de cada entidad.
 * Agrega departamentos con el mismo formato.
 */
(() => {
  'use strict';

  const DEPARTAMENTOS = [
    { codigo: 'BOG', nombre: 'Bogotá D.C.', entidad: 'Secretaría Distrital de Hacienda', portal: 'https://www.haciendabogota.gov.co/',
      municipios: ['BOGOTA'] },
    { codigo: 'ANT', nombre: 'Antioquia', entidad: 'Gobernación de Antioquia', portal: 'https://www.antioquia.gov.co/',
      municipios: ['ANTIOQUIA', 'MEDELLIN', 'ENVIGADO', 'ITAGUI', 'BELLO', 'SABANETA', 'RIONEGRO', 'LA ESTRELLA', 'COPACABANA', 'GIRARDOTA', 'APARTADO', 'CAUCASIA'] },
    { codigo: 'ATL', nombre: 'Atlántico', entidad: 'Gobernación del Atlántico', portal: 'https://www.atlantico.gov.co/',
      municipios: ['ATLANTICO', 'BARRANQUILLA', 'SOLEDAD', 'MALAMBO', 'PUERTO COLOMBIA', 'GALAPA', 'SABANALARGA', 'BARANOA'] },
    { codigo: 'VAC', nombre: 'Valle del Cauca', entidad: 'Gobernación del Valle del Cauca', portal: 'https://www.valledelcauca.gov.co/',
      municipios: ['VALLE', 'CALI', 'PALMIRA', 'YUMBO', 'JAMUNDI', 'BUGA', 'TULUA', 'CARTAGO', 'BUENAVENTURA', 'CANDELARIA'] },
    { codigo: 'CUN', nombre: 'Cundinamarca', entidad: 'Gobernación de Cundinamarca', portal: 'https://www.cundinamarca.gov.co/',
      municipios: ['CUNDINAMARCA', 'CHIA', 'COTA', 'FUNZA', 'MOSQUERA', 'SOACHA', 'ZIPAQUIRA', 'CAJICA', 'FACATATIVA', 'MADRID', 'TOCANCIPA', 'SOPO', 'GIRARDOT', 'FUSAGASUGA', 'LA CALERA', 'TENJO', 'SIBERIA'] },
    { codigo: 'SAN', nombre: 'Santander', entidad: 'Gobernación de Santander', portal: 'https://www.santander.gov.co/',
      municipios: ['SANTANDER', 'BUCARAMANGA', 'FLORIDABLANCA', 'GIRON', 'PIEDECUESTA', 'BARRANCABERMEJA', 'SAN GIL', 'SOCORRO'] },
    { codigo: 'BOL', nombre: 'Bolívar', entidad: 'Gobernación de Bolívar', portal: 'https://www.bolivar.gov.co/',
      municipios: ['BOLIVAR', 'CARTAGENA', 'TURBACO', 'MAGANGUE', 'ARJONA'] },
  ];

  // Mayúsculas, sin tildes y solo letras/números separados por un espacio
  const normalizar = (s) => String(s || '').toUpperCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Z0-9]+/g, ' ').trim();

  function detectar(organismo) {
    const org = ` ${normalizar(organismo)} `;
    let mejor = null, largo = 0;
    for (const d of DEPARTAMENTOS) {
      for (const m of d.municipios) {
        if (org.includes(` ${m} `) && m.length > largo) { mejor = d; largo = m.length; }
      }
    }
    return mejor;
  }

  window.Departamentos = { lista: DEPARTAMENTOS, detectar };
})();

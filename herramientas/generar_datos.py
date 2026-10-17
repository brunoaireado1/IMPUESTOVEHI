#!/usr/bin/env python3
"""
Convierte el PDF de la tabla de base gravable (avalúos) del Ministerio de
Transporte en el archivo de datos que usa el buscador.

Uso:
    pip install pdfplumber
    python3 herramientas/generar_datos.py anexo.pdf [otro_anexo.pdf ...] \
        --anio 2026 --fuente "Resolución 20253040048935 de 2025, Anexo 1" \
        --salida privado/avaluos-2026.php

Puedes pasar varios anexos (automóviles, camionetas, motos...) en un solo comando.

Formato de salida (solo lo lee el servidor; nunca se envía al navegador):
    <?php http_response_code(404); exit; ?>
    {
      anioFiscal, fuente, anios: [2001..2025],
      marcas: ["ACURA", ...],
      filas: [[id, marcaIdx, tipo, clase, linea, cilindraje, [valores...]], ...]
    }
    - valores en MILES DE PESOS, uno por año modelo; null = sin valor;
      texto (p. ej. "2E+06") = el PDF oficial imprime la cifra en notación científica.
"""
import argparse
import json
import re

import pdfplumber


def limpiar(celda):
    return re.sub(r"\s+", " ", celda or "").strip()


def normalizar_tipo(t):
    t = t.upper()
    # Algunas celdas del anexo de motos vienen cortadas: "MOTOCICLETAS Y MOT"
    if t.startswith("MOTOCICLETAS Y MOT"):
        return "MOTOCICLETAS Y MOTOCARROS"
    return t


def normalizar_clase(c):
    c = c.upper()
    # Texto vertical superpuesto en el PDF: "MOTOCARRO" sale como "OMCOATRORCOASRRO"
    c = re.sub(r"^OMCOATRORCOASRRO", "MOTOCARRO", c)
    return c


def extraer(ruta):
    filas, anios = [], None
    with pdfplumber.open(ruta) as pdf:
        for pagina in pdf.pages:
            for tabla in pagina.extract_tables():
                for fila in tabla:
                    celdas = [limpiar(c) for c in fila]
                    if not celdas:
                        continue
                    # Encabezado: la fila que trae las columnas de año
                    # ("2001 y Anteriores", "2002", ... "2025"). En algunos anexos
                    # la celda "ID" viene combinada con TIPO, CLASE, etc.
                    if not celdas[0].isdigit() and any(c == "2002" for c in celdas):
                        anios = []
                        for c in celdas:
                            m = re.match(r"^((19|20)\d{2})", c)
                            if m:
                                anios.append(int(m.group(1)))
                        continue
                    if not celdas[0].isdigit() or not anios:
                        continue
                    valores_txt = celdas[-len(anios):]
                    filas.append({
                        "id": int(celdas[0]),
                        "tipo": normalizar_tipo(celdas[1]),
                        "clase": normalizar_clase(celdas[2]),
                        "marca": celdas[3],
                        "linea": celdas[4],
                        "cilindraje": int(celdas[5]) if celdas[5].isdigit() else 0,
                        "valores": dict(zip(anios, valores_txt)),
                    })
    return filas


def valor(txt):
    if not txt:
        return None
    if txt.isdigit():
        return int(txt)
    return txt  # p. ej. "2E+06": se conserva tal como lo imprime el PDF


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("pdfs", nargs="+")
    ap.add_argument("--anio", type=int, required=True)
    ap.add_argument("--fuente", required=True)
    ap.add_argument("--salida", default="privado/avaluos.php")
    a = ap.parse_args()

    todas = []
    for ruta in a.pdfs:
        f = extraer(ruta)
        print(f"{ruta}: {len(f)} filas")
        todas += f

    anios = sorted({y for f in todas for y in f["valores"]})
    marcas = sorted({f["marca"] for f in todas})
    idx = {m: i for i, m in enumerate(marcas)}
    datos = {
        "anioFiscal": a.anio,
        "fuente": a.fuente,
        "anios": anios,
        "marcas": marcas,
        "filas": [
            [f["id"], idx[f["marca"]], f["tipo"], f["clase"], f["linea"], f["cilindraje"],
             [valor(f["valores"].get(y)) for y in anios]]
            for f in todas
        ],
    }
    raros = sum(1 for f in datos["filas"] for v in f[6] if isinstance(v, str))
    with open(a.salida, "w", encoding="utf-8") as out:
        # Primera línea: corta cualquier acceso por URL; PHP lee el JSON de la segunda línea
        out.write("<?php http_response_code(404); exit; ?>\n")
        json.dump(datos, out, ensure_ascii=False, separators=(",", ":"))
        out.write("\n")
    print(f"Listo: {len(todas)} líneas, {len(marcas)} marcas, años {anios[0]}–{anios[-1]} -> {a.salida}")
    if raros:
        print(f"Aviso: {raros} valores vienen en notación científica en el PDF (ej. 2E+06) y se marcan como ilegibles.")


if __name__ == "__main__":
    main()

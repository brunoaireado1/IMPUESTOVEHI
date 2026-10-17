<?php
/**
 * Avalúos: búsqueda en la tabla oficial y cálculo del impuesto (lado servidor).
 * Los datos viven en datos/avaluos-AAAA.php y nunca se envían al navegador.
 */

declare(strict_types=1);

class Avaluos
{
    // Tarifas año fiscal 2026 — Decreto 1457 de 2025 (MinHacienda)
    const TARIFAS = [
        ['hasta' => 57349000,  'tarifa' => 0.015],
        ['hasta' => 129032000, 'tarifa' => 0.025],
        ['hasta' => INF,       'tarifa' => 0.035],
    ];
    const TARIFA_MOTOS    = 0.015; // Ley 488 de 1998, motos de más de 125 c.c.
    const TOPE_ELECTRICOS = 0.01;  // Ley 1964 de 2019

    const TILDES = [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C', 'Ý' => 'Y',
    ];

    private array $d;
    private array $porMarca = [];
    public int $minAnio;
    public int $maxAnio;

    public function __construct(string $archivo)
    {
        $raw = file_get_contents($archivo);
        if ($raw === false) throw new RuntimeException("No se pudo leer $archivo");
        // Quita la primera línea de protección (la que corta el acceso por URL)
        $raw = substr($raw, strpos($raw, "\n") + 1);
        $this->d = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->minAnio = $this->d['anios'][0];
        $this->maxAnio = end($this->d['anios']);

        foreach ($this->d['filas'] as $i => [$id, $mi, $tipo, $clase, $linea, $cc, $valores]) {
            $marca = $this->d['marcas'][$mi];
            $lineaN = self::normalizar($linea);
            $f = [
                'key' => $i, 'marca' => $marca, 'tipo' => $tipo, 'clase' => $clase, 'linea' => $linea,
                'cc' => $cc, 'valores' => $valores,
                'lineaN' => $lineaN,
                'lineaTok' => explode(' ', $lineaN),
                'generica' => (bool) preg_match('/NO INCLUIDOS/i', $linea),
                'esMoto' => $tipo === 'MOTOCICLETAS Y MOTOCARROS',
                'esElectrico' => $tipo === 'ELECTRICOS' || (bool) preg_match('/ELECTRIC/i', $clase),
            ];
            $this->porMarca[self::normalizar($marca)][] = $f;
        }
    }

    public function anioFiscal(): int { return $this->d['anioFiscal']; }

    public static function normalizar($s): string
    {
        $s = mb_strtoupper((string) ($s ?? ''), 'UTF-8');
        $s = strtr($s, self::TILDES);
        $s = preg_replace('/[^A-Z0-9.]+/', ' ', $s);
        $s = preg_replace('/([A-Z])(\d)/', '$1 $2', $s);
        $s = preg_replace('/(\d)([A-Z])/', '$1 $2', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    public function idxAnio($modelo): int
    {
        $m = (int) $modelo ?: $this->maxAnio;
        $a = min(max($m, $this->minAnio), $this->maxAnio);
        return array_search($a, $this->d['anios'], true);
    }

    /** Tablas que corresponden a la clase y combustible del vehículo. */
    private function tablasPara(array $veh): array
    {
        $clase = self::normalizar($veh['clase'] ?? '');
        $comb = self::normalizar($veh['combustible'] ?? '');
        $t = [];
        if (preg_match('/MOTO|CUATRIMOTO|MOTOCARRO|CICLOMOTOR|TRICIMOTO|MOTOTRICICLO|CUADRICICLO/', $clase)) $t[] = 'MOTOCICLETAS Y MOTOCARROS';
        elseif (preg_match('/AMBULANCIA/', $clase)) $t[] = 'AMBULANCIAS';
        elseif (preg_match('/BUS|MICROBUS|BUSETA/', $clase)) $t[] = 'PASAJEROS';
        elseif (preg_match('/CAMION|VOLQUETA|TRACTO|FURGON|GRUA/', $clase) && !preg_match('/CAMIONETA/', $clase)) $t[] = 'CARGA';
        elseif (preg_match('/CAMIONETA|CAMPERO/', $clase)) { $t[] = 'CAMIONETAS Y CAMPEROS'; $t[] = 'CAMIONETAS DOBLECABINA'; }
        elseif (preg_match('/AUTOMOVIL/', $clase)) $t[] = 'AUTOMOVILES';
        if (preg_match('/ELECTRIC/', $comb) && !preg_match('/GASOLINA|DIESEL|HIBRID/', $comb)) $t[] = 'ELECTRICOS';
        if (preg_match('/HIBRID/', $comb) || (preg_match('/ELECTRIC/', $comb) && preg_match('/GASOLINA|DIESEL/', $comb))) $t[] = 'HIBRIDOS';
        // Servicio público de pasajeros (taxis) está en la tabla de pasajeros
        if (preg_match('/PUBLIC/', self::normalizar($veh['servicio'] ?? ''))) $t[] = 'PASAJEROS';
        return array_unique($t);
    }

    /** Puntúa todas las líneas de la misma marca frente a los datos del vehículo (mejor primero). */
    private function puntuar(array $veh): array
    {
        $marcaN = self::normalizar($veh['marca'] ?? '');
        $filas = $this->porMarca[$marcaN] ?? null;
        if ($filas === null) {
            // Marca escrita distinto ("MERCEDES-BENZ" / "MERCEDES BENZ AG"): busca por contención
            $filas = [];
            if ($marcaN !== '') {
                foreach ($this->porMarca as $k => $fs) {
                    if (str_starts_with((string) $k, $marcaN) || str_starts_with($marcaN, (string) $k)) array_push($filas, ...$fs);
                }
            }
        }
        if (!$filas) return [];

        $marcaTok = explode(' ', $marcaN);
        $lineaTok = array_values(array_filter(explode(' ', self::normalizar($veh['linea'] ?? '')),
            fn($t) => $t !== '' && !in_array($t, $marcaTok, true)));
        $cc = (int) ($veh['cilindraje'] ?? 0);
        $tablas = $this->tablasPara($veh);
        $ia = $this->idxAnio($veh['modelo'] ?? null);
        $coupeVeh = (bool) preg_match('/COUPE/', self::normalizar($veh['carroceria'] ?? ''));
        $publico = (bool) preg_match('/PUBLIC/', self::normalizar($veh['servicio'] ?? ''));

        $res = [];
        foreach ($filas as $f) {
            if ($lineaTok) {
                // Coincidencia por palabra completa, o parcial por prefijo ("X3" ~ "X3M")
                $comunes = 0;
                foreach ($lineaTok as $t) {
                    if (in_array($t, $f['lineaTok'], true)) { $comunes += 1; continue; }
                    if (strlen($t) >= 2) {
                        foreach ($f['lineaTok'] as $u) {
                            if (strlen($u) >= 2 && (str_starts_with($u, $t) || str_starts_with($t, $u))) { $comunes += 0.5; break; }
                        }
                    }
                }
                if ($comunes) {
                    $cobertura = min(1, $comunes / count($lineaTok));
                    $precision = min(1, $comunes / count($f['lineaTok']));
                    $score = $cobertura * 0.7 + $precision * 0.3;
                    if ($cobertura < 1) $score -= 0.15;
                } else {
                    // Sin palabras en común: solo como último recurso (cilindraje y tipo deciden)
                    $score = -0.6;
                }
            } else {
                $score = 0.3;
            }
            if ($cc > 0 && $f['cc'] > 1 && !$f['esElectrico']) {
                $d = abs($cc - $f['cc']);
                $score += $d <= 60 ? 0.25 : ($d <= 250 ? 0.05 : -0.3);
            }
            if ($tablas) $score += in_array($f['tipo'], $tablas, true) ? 0.2 : -0.15;
            // Carrocería: distingue "GLC 43" de "GLC 43 COUPE"
            $coupeFila = in_array('COUPE', $f['lineaTok'], true);
            if ($coupeFila !== $coupeVeh && !empty($veh['carroceria'])) $score -= 0.15;
            // Versiones TAXI solo para servicio público
            $taxi = in_array('TAXI', $f['lineaTok'], true);
            if ($taxi && !$publico) $score -= 0.3;
            if ($publico && $taxi) $score += 0.15;
            if (($f['valores'][$ia] ?? null) === null) $score -= 0.5;
            if ($f['generica']) $score -= 1;
            $res[] = ['f' => $f, 'score' => round($score, 3)];
        }
        usort($res, fn($a, $b) => ($b['score'] <=> $a['score']) ?: self::compararTexto($a['f']['linea'], $b['f']['linea']));
        return $res;
    }

    /**
     * Orden alfabético estilo ICU 'es' con números naturales (como localeCompare
     * del navegador), sin depender de la extensión intl: signos < dígitos < letras,
     * los números se comparan por valor, la Ñ va después de la N.
     */
    private static function compararTexto(string $a, string $b): int
    {
        $ka = self::claveOrden($a);
        $kb = self::claveOrden($b);
        $n = min(count($ka), count($kb));
        for ($i = 0; $i < $n; $i++) {
            if ($ka[$i] !== $kb[$i]) return $ka[$i] <=> $kb[$i];
        }
        return (count($ka) <=> count($kb)) ?: strcmp($a, $b);
    }

    private static function claveOrden(string $s): array
    {
        $s = strtr(mb_strtoupper($s, 'UTF-8'), ['Ñ' => "N\x7F"] + self::TILDES);
        preg_match_all('/\d+|./su', $s, $m);
        $k = [];
        foreach ($m[0] as $t) {
            if (ctype_digit($t)) { $k[] = [1, (int) $t]; continue; }
            if ($t === "\x7F") { $k[count($k) - 1][2] = 1; continue; } // Ñ = N + desempate
            $k[] = ctype_alpha($t) ? [2, ord($t), 0] : [0, mb_ord($t, 'UTF-8')];
        }
        return $k;
    }

    /** Versión exacta o la más parecida; null si la marca no está en la tabla. */
    public function emparejar(array $veh): ?array
    {
        $todas = $this->puntuar($veh);
        if (!$todas) return null;

        // Si hay empate se prefiere la que tiene avalúo para el año
        $ia = $this->idxAnio($veh['modelo'] ?? null);
        $tope = $todas[0]['score'];
        $mejor = $todas[0];
        foreach ($todas as $r) {
            if ($r['score'] < $tope - 0.05) break;
            if (is_int($r['f']['valores'][$ia] ?? null)) { $mejor = $r; break; }
        }
        return $mejor['f'];
    }

    /** Calcula el impuesto del año fiscal. */
    public function calcular(array $fila, array $veh): array
    {
        $modelo = (int) ($veh['modelo'] ?? 0);
        $ia = $this->idxAnio($modelo);
        $bruto = $fila['valores'][$ia] ?? null;
        $notas = [];
        $base = ['modelo' => $this->d['anios'][$ia], 'estado' => 'ok', 'avaluo' => null, 'tarifa' => null,
                 'impuesto' => null, 'tramo' => null, 'topado' => false, 'esMoto' => $fila['esMoto']];

        if ($modelo > $this->maxAnio) return ['estado' => 'nuevo', 'notas' => $notas] + $base;
        if ($modelo && $modelo < $this->minAnio) $notas[] = "Para modelos anteriores a {$this->minAnio} la tabla oficial usa el valor de “{$this->minAnio} y anteriores”.";
        if ($bruto === null) return ['estado' => 'sin_valor', 'notas' => $notas] + $base;
        if (is_string($bruto)) return ['estado' => 'ilegible', 'bruto' => $bruto, 'notas' => $notas] + $base;

        $avaluo = $bruto * 1000;

        if (self::normalizar($veh['antiguo_clasico'] ?? '') === 'SI') {
            $v2001 = $fila['valores'][0] ?? null;
            if (is_int($v2001)) {
                $avaluo = (int) round($v2001 * 1000 * 0.5);
                $notas[] = 'Vehículo antiguo o clásico: la base es el 50 % del avalúo del modelo 2001 y anteriores.';
            }
        }

        $servicio = self::normalizar($veh['servicio'] ?? '');
        $publico = (bool) preg_match('/PUBLIC/', $servicio);
        $oficial = (bool) preg_match('/OFICIAL/', $servicio);
        if ($publico || $oficial) {
            return ['estado' => $publico ? 'publico' : 'oficial', 'avaluo' => $avaluo, 'notas' => $notas] + $base;
        }

        $comb = self::normalizar($veh['combustible'] ?? '');
        $electrico = $fila['esElectrico'] || (preg_match('/ELECTRIC/', $comb) && !preg_match('/GASOLINA|DIESEL|HIBRID/', $comb));

        if ($fila['esMoto'] && !$fila['esElectrico'] && $fila['cc'] > 0 && $fila['cc'] <= 125) {
            return ['estado' => 'exento', 'avaluo' => $avaluo, 'impuesto' => 0, 'notas' => $notas] + $base;
        }

        $tramo = null;
        if ($fila['esMoto']) {
            $tarifa = self::TARIFA_MOTOS;
        } else {
            foreach (self::TARIFAS as $i => $t) if ($avaluo <= $t['hasta']) { $tramo = $i; break; }
            $tarifa = self::TARIFAS[$tramo]['tarifa'];
        }
        $topado = false;
        if ($electrico && $tarifa > self::TOPE_ELECTRICOS) { $tarifa = self::TOPE_ELECTRICOS; $topado = true; }

        $impuesto = (int) (round($avaluo * $tarifa / 1000) * 1000);
        return ['avaluo' => $avaluo, 'tarifa' => $tarifa, 'tramo' => $tramo, 'impuesto' => $impuesto,
                'topado' => $topado, 'notas' => $notas] + $base;
    }
}

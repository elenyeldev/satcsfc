<?php
// Configuración detallada de errores
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr'); // Log directly to Railway console

// Headers para desarrollo
header('X-Developer-Mode: Debug');

function forceNoCache() {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
function jsonResponse($data, $status = 200) {
    forceNoCache();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- LÓGICA DE EXTRACCIÓN Y HELPER FUNCTIONS ---
function extract_regimenes_list($html): array {
    $regimenes = [];
    if (empty($html)) return [];
    $cleanHtml = preg_replace('/\s+/', ' ', $html);
    preg_match_all('/Régimen:.*?<\/td>\s*<td[^>]*>(.*?)<\/td>.*?Fecha de alta:.*?<\/td>\s*<td[^>]*>(.*?)<\/td>(?:.*?Fecha de baja:.*?<\/td>\s*<td[^>]*>(.*?)<\/td>)?/is', $cleanHtml, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $regimenes[] = [
            'regimen' => preg_replace('/\bRégimen\s*:\s*/iu', '', trim(strip_tags($m[1]))),
            'fecha_inicio' => preg_replace('/\bFecha\s+de\s+alta\s*:\s*/iu', '', trim(strip_tags($m[2]))),
            'fecha_fin' => isset($m[3]) ? preg_replace('/\bFecha\s+de\s+baja\s*:\s*/iu', '', trim(strip_tags($m[3]))) : ''
        ];
    }
    return $regimenes;
}

// === Normalización y extracción defensiva ===
function normalize_sat_html(string $html): string {
    $html = preg_replace('/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/iu', '', $html);
    $html = preg_replace('/<\s*(br|p|li|td|tr|div|h[1-6])\b[^>]*>/iu', "\n", $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace("/\n{2,}/", "\n", $text);
    $text = preg_replace('/\$\s*\(function\s*\(\)\s*\{[\s\S]*?\}\s*\)\s*;?/u', '', $text);
    $text = preg_replace('/PrimeFaces\.cw\([^\)]*\);\s*/ui', '', $text);
    return trim($text);
}

function extract_label_value(string $text, string $html, array $labels): string {
    foreach ($labels as $lbl) {
        $patternSameLine = '/' . preg_quote($lbl, '/') . '\s*[:\s]\s*([^\n\r<]+)/iu';
        if (preg_match($patternSameLine, $text, $m)) return trim($m[1]); 
        $patternNextLine = '/' . preg_quote($lbl, '/') . '\s*[:\s]*[\r\n]+\s*([^\n\r<]+)/iu';
        if (preg_match($patternNextLine, $text, $m)) return trim($m[1]);
        if ($html !== '') {
            $patternHtml = '/'.preg_quote($lbl, '/').'\s*[:\s]*\s*(?:<\/?\w+[^>]*>\s*)*([^<>\n\r]+)/iu';
            if (preg_match($patternHtml, $html, $mh)) return trim(html_entity_decode($mh[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')); 
        }
    }
    return '';
}

function sanitize_value(string $value, array $allPossibleLabels): string {
    $v = $value;
    // Si el valor contiene otra etiqueta (desfase de extracción)
    foreach ($allPossibleLabels as $label) {
        $cleanLabel = mb_strtolower(trim($label));
        $vLower = mb_strtolower($v);
        $pos = mb_strpos($vLower, $cleanLabel . ':');
        if ($pos !== false) $v = mb_substr($v, 0, $pos);
    }
    // Limpiar etiquetas redundantes conocidas que el SAT inserta en las celdas
    $v = preg_replace('/\bFecha\s+de\s+alta\s*:\s*/iu', '', $v);
    $v = preg_replace('/\bRégimen\s*:\s*/iu', '', $v);
    $v = str_replace([':', '"', "'"], '', $v);
    return trim($v);
}

function extract_sat_data($html): array {
    $text = ($html !== false && $html !== null) ? normalize_sat_html($html) : '';
    $labels = [
        'CURP' => ['CURP'],
        'Nombre' => ['Nombre', 'Nombre(s)', 'Denominación o Razón Social'],
        'Apellido Paterno' => ['Apellido Paterno', 'PrimerApellido'],
        'Apellido Materno' => ['Apellido Materno', 'SegundoApellido'],
        'Fecha de Inicio de operaciones' => ['Fecha de Inicio de operaciones', 'Fechainiciodeoperaciones'],
        'Situación del contribuyente' => ['Situación del contribuyente', 'Estatusenelpadrón', 'Situación'],
        'Fecha del último cambio de situación' => ['Fecha del último cambio de situación', 'Fechadeúltimocambiodeestado'],
        'Entidad Federativa' => ['Entidad Federativa', 'NombredelaEntidadFederativa'],
        'Municipio o Demarcación Territorial' => ['Municipio o delegación', 'Municipio', 'NombredelMunicipiooDemarcaciónTerritorial', 'Delegación', 'Municipio o Delegación'],
        'Nombre de la Localidad' => ['Nombre de la Localidad', 'Localidad', 'NombredelaLocalidad'],
        'Colonia' => ['Colonia', 'NombredelaColonia'],
        'Tipo de vialidad' => ['Tipo de vialidad', 'TipodeVialidad'],
        'Nombre de la vialidad' => ['Nombre de la vialidad', 'NombredeVialidad'],
        'Número exterior' => ['Número exterior', 'NúmeroExterior'],
        'Número interior' => ['Número interior', 'NúmeroInterior'],
        'CP' => ['CP', 'CódigoPostal'],
        'Correo electrónico' => ['Correo electrónico'],
        'AL' => ['AL'],
        'Régimen' => ['Régimen', 'Regímenes', 'Régimen Fiscal'],
        'Entre Calle' => ['Entre Calle', 'Entre calle', 'Entre calles', 'EntreCalle'],
        'Y Calle' => ['Y Calle', 'Y calle', 'YCalle'],
        'Lugar y Fecha de Emisión' => ['Lugar y fecha de emisión', 'Fecha de emisión'],
    ];
    $data = [];
    foreach ($labels as $key => $choices) {
        $val = extract_label_value($text, $html, $choices);
        if ($val === '') {
            foreach ($choices as $choice) {
                // Buscar la etiqueta sin espacios ni acentos por si el HTML viene compactado
                $compactChoice = preg_replace('/[^a-z0-9]/i', '', $choice);
                $pattern = '/' . preg_quote($choice, '/') . '\s*[:\s]*([^\n\r<]+)/iu';
                if (preg_match($pattern, $text, $m)) { $val = trim($m[1]); break; }
            }
        }
        $data[$key] = $val;
    }

    // Unificación solicitada: Localidad y Demarcación Territorial suelen ser lo mismo
    if (empty($data['Nombre de la Localidad']) && !empty($data['Municipio o Demarcación Territorial'])) {
        $data['Nombre de la Localidad'] = $data['Municipio o Demarcación Territorial'];
    }

    // Fallback específico para Lugar y Fecha de Emisión si no se detectó
    if (empty($data['Lugar y Fecha de Emisión'])) {
        $pattern = '/([A-Z\s,]+A\s+\d{1,2}\s+DE\s+[A-Z]+\s+DE\s+\d{4})/u';
        if (preg_match($pattern, $text, $m)) {
            $data['Lugar y Fecha de Emisión'] = trim($m[1]);
        } else {
            // Último recurso: Generar uno basado en la fecha actual si es para la visualización
            $meses = ["ENERO","FEBRERO","MARZO","ABRIL","MAYO","JUNIO","JULIO","AGOSTO","SEPTIEMBRE","OCTUBRE","NOVIEMBRE","DICIEMBRE"];
            $fecha = date('d') . " DE " . $meses[date('n')-1] . " DE " . date('Y');
            $data['Lugar y Fecha de Emisión'] = "CIUDAD DE MEXICO A " . $fecha;
        }
    }

    $allLabelsFlat = [];
    foreach ($labels as $choices) { foreach ($choices as $lbl) { $allLabelsFlat[] = $lbl; } }
    foreach ($data as $k => $v) { $data[$k] = sanitize_value($v, $allLabelsFlat); }
    
    // Agregar la lista detallada de regímenes
    $regList = extract_regimenes_list($html);
    $data['RegimenesList'] = $regList;
    
    // Sobrescribir el campo de Régimen principal con el último encontrado (solicitado para el PDF)
    if (!empty($regList)) {
        $lastReg = end($regList);
        $data['Régimen'] = $lastReg['regimen'];
    }
    return $data;
}

function fetchHtml(string $url) {
    // Implementación simplificada compatible
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: es-MX,es;q=0.9',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => $headers,
        // Solución para error:0A00018A:SSL routines::dh key too small
        // Permite conectar con servidores con seguridad TLS antigua/debil
        CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1'
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        error_log("CURL Error: " . curl_error($ch));
    } else {
        error_log("CURL Success: HTML Length = " . strlen($resp));
    }
    // curl_close($ch); // Deprecated en versiones recientes de FrankenPHP/PHP 8.4+
    return $resp;
}

function getPdfCoordMap() {
    return [
       'RFC' => ['x' => 206, 'y' => 246],
       'CURP' => ['x' => 206, 'y' => 268],
       'Nombre' => ['x' => 206, 'y' => 290],
       'Apellido Paterno' => ['x' => 206, 'y' => 312],
       'Apellido Materno' => ['x' => 206, 'y' => 334],
       'Fecha de Inicio de operaciones' => ['x' => 206, 'y' => 356],
       'Situación del contribuyente' => ['x' => 206, 'y' => 377],
       'Fecha del último cambio de situación' => ['x' => 206, 'y' => 400],
       'Nombre Comercial' => ['x' => 206, 'y' => 422],
       'CP' => ['x' => 64, 'y' => 476],
       'Nombre de la vialidad' => ['x' => 85, 'y' => 497],
       'Nombre de la Localidad' => ['x' => 100, 'y' => 541],
       'Entidad Federativa' => ['x' => 134, 'y' => 563],
       'Tipo de vialidad' => ['x' => 346, 'y' => 476],
       'Número exterior' => ['x' => 347, 'y' => 497],
       'Número interior' => ['x' => 69, 'y' => 519],
       'Colonia' => ['x' => 367, 'y' => 519],
       'Municipio o Demarcación Territorial' => ['x' => 467, 'y' => 541],
       'Entre Calle' => ['x' => 330, 'y' => 564],
       'Y Calle' => ['x' => 330, 'y' => 586],
       'Lugar y Fecha de Emisión' => ['x' => 100, 'y' => 740],
    ];
}

// --- API ENDPOINT HANDLER ---
// Si viene una petición POST o GET con json=1 para búsqueda
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (!empty($_GET['json']) && $_GET['json'] == 1)) {
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $idcif = trim($input['idcif'] ?? '');
    $rfc = trim($input['rfc'] ?? '');
    
    // Validación Backend Básica
    if (!preg_match('/^\d+$/', $idcif)) {
        jsonResponse(['error' => 'El ID CIF debe contener solo números.'], 400);
    }
    // Validación Backend de RFC (Estructura)
    if (!preg_match('/^([A-Z&]{3,4})([0-9]{2})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])([A-Z0-9]{3})$/', $rfc)) {
        jsonResponse(['error' => 'El formato del RFC es inválido. Recuerde: 3-4 letras, 6 números y 3 caracteres de homoclave.'], 400);
    }

    $baseUrl = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=';
    $link = $baseUrl . $idcif . '_' . $rfc;
    
    $html = fetchHtml($link);
    $data = extract_sat_data($html);
    
    // Validar si realmente obtuvimos datos
    if (empty($data['Nombre']) && empty($data['Denominación o Razón Social'])) {
        jsonResponse(['error' => '<b>¡No se encontraron datos!</b><br> Verifique su IdCIF y su RFC este correctos.'], 404);
    }

    // Respuesta exitosa JSON
    jsonResponse([
        'data' => $data,
        'coords' => getPdfCoordMap(),
        'rfc' => $rfc,
        'link' => $link,
        'idCif' => $idcif
    ]);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consulta SAT - Gobierno de México</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/styles.css">

</head>
<body class="bg-light min-vh-100 d-flex flex-column">
  
  <header class="brand-gradient text-white shadow-sm">
    <div class="container py-3 d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-4">
        <div>
          <h1 class="h6 m-0 fw-bold" style="letter-spacing: -0.025em;">Cédula de Identificación Fiscal</h1>
          <small class="brand-accent text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Validador QR del SAT</small>
        </div>
      </div>
      <div class="d-none d-md-block text-end">
        <div class="shcp-sub mb-1 text-uppercase">Secretaría de Hacienda</div>
        <div class="shcp-logo fw-bold">SHCP | SAT</div>
      </div>
    </div>
  </header>

  <div class="container py-5 flex-grow-1">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 m-0 fw-bold text-secondary text-opacity-75">Generador de Constancia (CSF)</h1>
      </div>

      <div class="card shadow-sm border-0 mb-3">
          <div class="card-body p-4">
              <!-- Se cambió align-items-end a align-items-start para evitar saltos por validación -->
              <form id="csfForm" class="row g-4 align-items-start" novalidate>
                  <div class="col-12 col-md-5">
                      <label for="idcif" class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">ID CIF</label>
                      <input type="text" id="idcif" name="idcif" class="form-control form-control-md bg-light border-0" 
                             placeholder="Ej: 17030158688" 
                             required pattern="\d+" 
                             title="Solo se permiten números"
                             oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                      <div class="invalid-feedback">El ID CIF debe contener solo números.</div>
                  </div>
                  <div class="col-12 col-md-4">
                      <label for="rfc" class="form-label fw-bold small text-muted text-uppercase" style="letter-spacing: 0.05em;">RFC</label>
                      <input type="text" id="rfc" name="rfc" class="form-control form-control-md bg-light border-0 text-uppercase" 
                             placeholder="Ej: GOAA850204DN3" 
                             required minlength="12" maxlength="13"
                             oninput="this.value = this.value.toUpperCase();">
                       <div class="invalid-feedback">El RFC debe tener entre 12 (Moral) y 13 (Física) caracteres.</div>
                  </div>
                  <!-- Contenedor del botón con mt-md-4 para alineación visual con labels -->
                  <div class="col-12 col-md-3"> 
                    <label class="form-label fw-bold small text-muted text-uppercase"></label>
                      <button type="submit" id="btnSearch" class="btn brand-btn w-100 py-2 fw-bold d-flex align-items-center justify-content-center gap-2 shadow-sm">
                          <span class="submit-text"><i class="bi bi-search"></i> GENERAR VALIDACIÓN</span>
                          <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                      </button>
                  </div>
              </form>
          </div>
      </div>

      <!-- Contenedor de Resultados (Oculto inicialmente) -->
      <div id="resultContainer" class="row justify-content-center d-none">
        <div class="col-12 col-lg-10">
          
          <!-- Componente de Alerta de Estatus -->
          <div id="statusAlert" class="mb-3"></div>

          <div class="card shadow border-0 overflow-hidden">
            <div class="card-header text-white fw-bold d-flex justify-content-between align-items-center py-2" style="background-color: var(--mx-guinda-osc);">
              <div class="d-flex align-items-center gap-3">
                  <div class="bg-white text-dark rounded-circle d-flex align-items-center justify-content-center" style="width:32px; height:32px;">
                    <i class="bi bi-file-earmark-pdf-fill"></i>
                  </div>
                  <span class="small" style="letter-spacing: 0.05em;">VISTA PREVIA DE CSF</span>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-light border-0 d-flex align-items-center gap-2 px-3" onclick="generatePdfPreview(false, 'ACTUALIZANDO DOCUMENTO...')" title="Refrescar">
                  <i class="bi bi-arrow-clockwise"></i> <span class="d-none d-sm-inline">Actualizar</span>
                </button>
                <div class="vr bg-white opacity-25"></div>
                <button id="btnDownload" class="btn btn-sm brand-btn fw-bold d-flex align-items-center gap-2 text-white px-3 shadow-none disabled" onclick="downloadPdf()" title="Descargar PDF" disabled>
                  <i class="bi bi-download"></i> <span class="d-none d-sm-inline">Descargar PDF</span>
                </button>
              </div>
            </div>
            <div class="card-body p-0 bg-secondary bg-opacity-10 position-relative">
               <div id="pdfLoader" class="position-absolute top-50 start-50 translate-middle text-center" style="z-index:10;">
                   <div class="spinner-border text-dark" role="status" style="width: 3rem; height: 3rem;"></div>
                   <div class="mt-3 fw-bold text-muted" style="letter-spacing: 0.05em;">GENERANDO DOCUMENTO...</div>
               </div>
               <iframe id="pdfViewer" style="width:100%; height:85vh; border:none; opacity:0; transition: opacity 0.5s;" src="about:blank" onload="document.getElementById('pdfLoader')?.classList.add('d-none'); this.style.opacity='1';"></iframe>
            </div>
            <div class="card-footer bg-white py-2 text-center text-muted small border-top">
                Documento generado basado en datos oficiales del SAT. <span class="fw-bold" id="docNameFooter">CSF_Document.pdf</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Mensaje Inicial -->
      <div id="welcomeMessage" class="text-center mt-5 text-muted opacity-50">
          <div class="display-1 mb-3"><i class="bi bi-qr-code-scan"></i></div>
          <p class="h5">Ingrese sus datos para visualizar la constancia.</p>
      </div>

  </div>

  <script src="https://unpkg.com/pdf-lib/dist/pdf-lib.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bwip-js/dist/bwip-js-min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Script de Desarrollo (Carga directa para asegurar ver cambios) -->
  <script src="js/app.js"></script>
  <script>
      // Fallback por si en algún momento se desea volver al minificado
      if (typeof generatePdfPreview === 'undefined') {
          console.warn('app.js no cargado, intentando fallback a minificado');
          const script = document.createElement('script');
          script.src = 'build/app.min.js';
          document.body.appendChild(script);
      }
  </script>

    <script>
      /**
       * Lógica Frontend de la Aplicación
       * Gestiona la validación del formulario, las interacciones de entrada del usuario
       * y la comunicación asíncrona con el backend para la generación de la CSF.
       */
      document.addEventListener('DOMContentLoaded', function() {
        // Elementos del DOM
        const form = document.getElementById('csfForm');
        const btnSearch = document.getElementById('btnSearch');
        const spinner = btnSearch.querySelector('.spinner-border');
        const btnText = btnSearch.querySelector('.submit-text');
        
        const resultsContainer = document.getElementById('resultContainer');
        const welcomeMessage = document.getElementById('welcomeMessage');
        const statusAlert = document.getElementById('statusAlert'); // Contenedor para notificaciones de estado

        const idcifInput = document.getElementById('idcif');
        const rfcInput = document.getElementById('rfc');

        // ==================================================
        // 1. VALIDADORES DE ENTRADA (Real-Time)
        // ==================================================

        // Validar ID CIF: Permitir solo números y teclas de control
        idcifInput.addEventListener('keydown', function(e) {
             // 1. Permitir atajos: Ctrl/Cmd + (C, V, A, X, etc.)
            if (e.ctrlKey || e.metaKey) {
                return;
            }
            // 2. Permitir teclas especiales de navegación y edición
            // 46=Supr, 8=Backspace, 9=Tab, 27=Esc, 13=Enter, 35=End, 36=Home, 37=Left, 38=Up, 39=Right, 40=Down
            if ([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 || (e.keyCode >= 35 && e.keyCode <= 40)) {
                 return;
            }

            // 3. Bloquear todo lo que NO sea número (Teclado superior 48-57, Numpad 96-105)
            if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                e.preventDefault();
                Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Solo se permiten números', showConfirmButton: false, timer: 1500 });
            }
        });

        // Limpieza adicional en el input (pegar texto con letras)
        idcifInput.addEventListener('input', function() {
           this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Validar RFC: Mayúsculas y caracteres válidos
        rfcInput.addEventListener('input', function(e) {
            let val = this.value.toUpperCase();
            // Evitar caracteres especiales raros (Solo permite A-Z, 0-9, &)
            const cleanVal = val.replace(/[^A-Z&0-9]/g, '');
            
            if (val !== cleanVal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'error',
                    title: 'Caracteres no permitidos',
                    text: 'Solo se permiten letras (A-Z), números y &',
                    showConfirmButton: false,
                    timer: 2000
                });
                this.value = cleanVal;
            } else {
                this.value = val;
            }
            
            // Validación visual de longitud
            if(this.value.length > 0 && this.value.length < 12) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        // Función para validación estricta de estructura RFC
        function validateRfcStructure(rfc) {
            // Regex Oficial SAT (modificado para no permitir Ñ): 
            // Persona Física: 4 letras, 6 números (YYMMDD), 3 homoclave
            // Persona Moral: 3 letras, 6 números (YYMMDD), 3 homoclave
            const re = /^([A-Z&]{3,4})([0-9]{2})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])([A-Z0-9]{3})$/;
            return re.test(rfc);
        }

        // ==================================================
        // 2. MANEJO DEL ENVÍO (Submit Wrapper)
        // ==================================================
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const idcif = idcifInput.value.trim();
            const rfc = rfcInput.value.trim();

            // A. Validar campos vacíos
            if (!idcif || !rfc) {
                Swal.fire({ icon: 'error', title: 'Campos Vacíos', text: 'Por favor ingrese tanto el ID CIF como el RFC.', confirmButtonColor: '#611232' });
                return;
            }

            // B. Validaciones de formato finales
            let isValid = true;
            if(!/^\d+$/.test(idcif)) {
                idcifInput.classList.add('is-invalid');
                isValid = false;
            }
            
            // Validación estricta de RFC
            if(!validateRfcStructure(rfc)) {
                rfcInput.classList.add('is-invalid');
                isValid = false;
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Estructura de RFC Inválida', 
                    html: `El RFC ingresado <b>"${rfc}"</b> no tiene el formato oficial esperado.<br><br>Recuerde:<br>• <b>Personas Físicas:</b> 4 letras, 6 números y 3 caracteres.<br>• <b>Personas Morales:</b> 3 letras, 6 números y 3 caracteres.`,
                    confirmButtonColor: '#611232' 
                });
                return;
            }

            if (!isValid) {
                Swal.fire({ icon: 'error', title: 'Datos Incompletos', text: 'Por favor verifique que los campos marcados en rojo sean correctos.', confirmButtonColor: '#611232' });
                return;
            }

            // C. Preparar Interfaz para Carga
            btnSearch.disabled = true;
            btnText.textContent = 'PROCESANDO...';
            spinner.classList.remove('d-none');
            
            // Ocultar resultados previos
            resultsContainer.classList.add('d-none');
            welcomeMessage.classList.add('d-none');
            if(statusAlert) statusAlert.innerHTML = '';

            const formData = new FormData();
            formData.append('idcif', idcif);
            formData.append('rfc', rfc);

            try {
                // D. Petición Asíncrona al Backend
                const response = await fetch('index.php', { method: 'POST', body: formData });
                
                // Verificar tipo de contenido
                const contentType = response.headers.get("content-type");
                let result;
                if (contentType && contentType.includes("application/json")) {
                     result = await response.json();
                } else {
                     throw new Error("Respuesta inválida del servidor (HTML no esperado).");
                }

                if (!response.ok || result.error) {
                    throw new Error(result.error || 'Error desconocido al consultar el SAT.');
                }

                // ==================================================
                // 3. PROCESAMIENTO DE RESPUESTA EXITOSA
                // ==================================================
                
                // Actualizar Estado Global de la App (Usado por app.js para el PDF)
                window.SatApp = result; 

                // Renderizar Alerta de Estado del Contribuyente
                const situacion = result.data['Situación del contribuyente'] || 'DESCONOCIDO';
                const situacionUpper = situacion.toUpperCase();
                let alertClass = 'alert-info';
                let iconClass = 'bi-info-circle-fill';
                let title = 'Aviso';
                let extraNote = '';

                if (situacionUpper.includes('ACTIVO') || situacionUpper.includes('REACTIVADO')) {
                    alertClass = 'alert-success';
                    iconClass = 'bi-check-circle-fill';
                    title = 'Contribuyente Localizado';
                } else if (situacionUpper.includes('SUSPENDIDO') || situacionUpper.includes('BAJA') || situacionUpper.includes('CANCELADO')) {
                    alertClass = 'alert-warning';
                    iconClass = 'bi-exclamation-triangle-fill';
                    title = 'Atención: Estatus Irregular';
                    extraNote = '<div class="mt-1 small text-dark opacity-75"><i class="bi bi-info-circle me-1"></i> Nota: Al estar <strong>SUSPENDIDO</strong>, este documento podría no ser válido para realizar ciertos trámites bancarios o legales.</div>';
                }

                if (statusAlert) {
                    statusAlert.innerHTML = `
                      <div class="alert ${alertClass} shadow-sm border-0" role="alert">
                        <div class="d-flex align-items-center">
                            <i class="bi ${iconClass} flex-shrink-0 me-3 fs-4"></i>
                            <div>
                                <h6 class="alert-heading fw-bold mb-1">${title}</h6>
                                <div class="small">El contribuyente se encuentra: <strong>${situacion}</strong></div>
                            </div>
                        </div>
                        ${extraNote}
                      </div>
                    `;
                }

                // Mostrar Contenedores
                welcomeMessage.classList.add('d-none');
                resultsContainer.classList.remove('d-none');
                
                // Iniciar Generación del PDF (Llamada a app.js)
                if(typeof generatePdfPreview === 'function') {
                    // Reiniciar visor para evitar cache visual
                    const viewer = document.getElementById('pdfViewer');
                    viewer.style.opacity = '0';
                    viewer.src = 'about:blank';
                    
                    // Pequeño delay para asegurar renderizado del DOM
                    setTimeout(() => generatePdfPreview(false, 'GENERANDO VISTA PREVIA...'), 100);
                }

            } catch (error) {
                console.error(error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: error.message || 'No se pudo completar la solicitud.',
                    confirmButtonColor: '#611232'
                });
                welcomeMessage.classList.remove('d-none');
            } finally {
                // E. Restaurar controles UI
                btnSearch.disabled = false;
                btnText.innerHTML = '<i class="bi bi-search"></i> GENERAR VALIDACIÓN';
                spinner.classList.add('d-none');
            }
        });
      });
    </script>
</body>
</html>
```
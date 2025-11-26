<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Database.php';
ini_set('display_errors','1'); error_reporting(E_ALL);

function isOrganizador(): bool {
    $tipo = $_SESSION['tipo_perfil'] ?? $_SESSION['perfil'] ?? null;
    return !empty($tipo) && mb_strtolower(trim((string)$tipo),'UTF-8') === 'organizador';
}

if (empty($_SESSION['id_usuario'])) { http_response_code(401); echo 'Autenticação requerida'; exit; }

$hash = trim((string)($_GET['hash'] ?? ''));
$download = isset($_GET['download']) ? true : false;
$force_regen = isset($_GET['force']) ? true : false;
if ($hash === '') { http_response_code(400); echo 'Hash inválido'; exit; }

try {
    $pdo = getDbConnection();
    
    $is_org = isOrganizador();
    // Query atualizada para buscar o conteúdo da imagem de fundo do banco de dados
    $sql = "
        SELECT c.id_certificado, c.data_emissao, c.codigo_hash,
               u.nome AS participante, e.titulo AS evento, COALESCE(e.carga_horaria::text,'') AS carga_horaria,
               cif.conteudo_imagem, cif.nome_arquivo_unico AS nome_imagem
        FROM certificado c
        JOIN inscricao i ON c.id_inscricao = i.id_inscricao
        JOIN usuario u ON i.id_usuario = u.id_usuario
        JOIN evento e ON i.id_evento = e.id_evento
        LEFT JOIN certificado_imagem_fundo cif ON c.id_imagem_fundo = cif.id_imagem_fundo
        WHERE c.codigo_hash = :h ";

    if (!$is_org) {
        $sql .= " AND i.id_usuario = :uid ";
    }
    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $params = [':h' => $hash];
    if (!$is_org) $params[':uid'] = (int)$_SESSION['id_usuario'];
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo 'Certificado não encontrado'; exit; }
} catch (Exception $e) {
    http_response_code(500); echo 'Erro DB'; exit;
}

// prepare variables and normalize to UTF-8
function to_utf8($s){
    if ($s === null) return '';
    if (!mb_check_encoding($s, 'UTF-8')) {
        $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252'], true) ?: 'ISO-8859-1';
        return mb_convert_encoding($s, 'UTF-8', $enc);
    }
    return $s;
}

$nome    = to_utf8($row['participante'] ?? '');
$titulo  = to_utf8($row['evento'] ?? '');
$carga   = to_utf8($row['carga_horaria'] ?? '');
$issued  = $row['data_emissao'] ? date('d/m/Y', strtotime($row['data_emissao'])) : date('d/m/Y');
$codigo_hash = to_utf8($row['codigo_hash'] ?? '');

// Dados da imagem de fundo (se existir)
$imagem_conteudo = $row['conteudo_imagem'] ?? null;
$imagem_nome = $row['nome_imagem'] ?? '';

// **CORREÇÃO**: Converte o stream do BLOB (resource) para string.
// Isso garante que $imagem_conteudo seja uma string para TCPDF, Imagick e o fallback HTML.
if (is_resource($imagem_conteudo)) {
    $imagem_conteudo = stream_get_contents($imagem_conteudo);
}
$imagem_ext = strtolower(pathinfo($imagem_nome, PATHINFO_EXTENSION));

$autoload = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
    if (class_exists('TCPDF')) {
        try {
            // try to use DejaVu font if installed in project fonts/
            $fontFile = __DIR__ . '/../../fonts/DejaVuSans.ttf';
            $useFont = false;
            if (file_exists($fontFile)) {
                $fontname = \TCPDF_FONTS::addTTFfont($fontFile, 'TrueTypeUnicode', '', 32);
                if ($fontname) $useFont = $fontname;
            }

            $pdf = new \TCPDF('L','pt','A4', true, 'UTF-8', false);
            $pdf->SetCreator('Sistema de Eventos');
            $pdf->SetAuthor($nome);
            $pdf->SetTitle('Certificado - ' . $titulo);
            $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
            $pdf->SetMargins(0,0,0); $pdf->SetAutoPageBreak(false, 0);
            $pdf->AddPage(); $pw=$pdf->getPageWidth(); $ph=$pdf->getPageHeight();

            // Usa a imagem do banco de dados se ela existir
            if ($imagem_conteudo) {
                $pdf->Image('@' . $imagem_conteudo, 0, 0, $pw, $ph, '', '', '', false, 300, '', false, false, 0);
            } else {
                $pdf->Rect(0,0,$pw,$ph,'F',[],[255,255,255]);
            }

            // translucent box
            $pdf->SetFillColor(255,255,255); $pdf->SetAlpha(0.8);
            $x=$pw*0.08; $y=$ph*0.58; $w=$pw*0.84; $h=$ph*0.28;
            $x=$pw*0.08; $y=$ph*0.55; $w=$pw*0.84; $h=$ph*0.32;
            $pdf->Rect($x,$y,$w,$h,'F'); $pdf->SetAlpha(1);

            // set unicode font
            $fontToUse = $useFont ?: 'dejavusans';
            $pdf->SetFont($fontToUse,'', max(14,(int)($pw*0.035)));

            $pdf->SetTextColor(15,17,42);
            $pdf->SetXY(0,$y+($h*0.10));
            $pdf->Cell($pw,0, $titulo, 0, 1, 'C', 0, '', 0);

            $pdf->SetFont($fontToUse,'', max(12,(int)($pw*0.024)));
            $pdf->SetXY(0,$y+($h*0.30));
            $pdf->Cell($pw,0, 'Certificamos que', 0, 1, 'C');

            $pdf->SetFont($fontToUse,'B', max(18,(int)($pw*0.05))); $pdf->SetXY(0,$y+($h*0.48));
            $pdf->Cell($pw,0, $nome, 0, 1, 'C');
            $pdf->SetFont($fontToUse,'', max(12,(int)($pw*0.024))); $pdf->SetXY(0,$y+($h*0.75));

            $mainText = 'por sua participação no evento "' . $titulo . '"';
            if ($carga) $mainText .= ', com carga horária de ' . $carga . ' horas';
            $mainText .= '.';
            $pdf->MultiCell($w, 0, $mainText, 0, 'C', 0, 1, $x, $y+($h*0.65));

            // footer hash
            $pdf->SetFont($useFont ?: 'dejavusans','', 9);
            $pdf->SetTextColor(100,100,100);
            $pdf->SetXY(20, $ph - 30);
            $pdf->Cell(0, 0, 'Código de rastreio: ' . $codigo_hash, 0, 0, 'L');

            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="certificado.pdf"');
            $pdf->Output('', 'I');
            exit;
        } catch (Exception $e) {
            // continue to other fallbacks
        }
    }
}

// If Imagick available: render raster with a TTF font (ensures UTF-8)
if (extension_loaded('imagick')) {
    try {
        $width = 2480; $height = (int)round($width * (11/16));
        $canvas = new \Imagick();
        $canvas->newImage($width, $height, new \ImagickPixel('white'));
        $canvas->setImageFormat('pdf');

        // Desenha a imagem de fundo se ela existir
        if ($imagem_conteudo) {
            $bg = new \Imagick();
            $bg->readImageBlob($imagem_conteudo);
            $bx = $bg->getImageWidth(); $by = $bg->getImageHeight();
            $scale = max($width / $bx, $height / $by);
            $bg->resizeImage((int)($bx*$scale),(int)($by*$scale), \Imagick::FILTER_LANCZOS, 1);
            $ox = (int)(($width - $bg->getImageWidth())/2);
            $oy = (int)(($height - $bg->getImageHeight())/2);
            $canvas->compositeImage($bg, \Imagick::COMPOSITE_OVER, $ox, $oy);
            $bg->destroy();
        }

        // choose a TTF font that supports accents
        $fontCandidates = [
            __DIR__ . '/../../fonts/DejaVuSans.ttf',        // project prefer
            __DIR__ . '/../../fonts/Arial.ttf',            // fallback project
            'C:\\Windows\\Fonts\\DejaVuSans.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
        ];
        $fontPath = null;
        foreach ($fontCandidates as $f) { if ($f && file_exists($f)) { $fontPath = $f; break; } }

        $draw = new \ImagickDraw();
        if ($fontPath) $draw->setFont($fontPath);
        $draw->setTextAntialias(true);
        $draw->setFillColor(new \ImagickPixel('#0f172a'));
        $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
        $draw->setTextEncoding('UTF-8');

        // translucent overlay for text area
        $overlay = new \ImagickDraw();
        $overlay->setFillColor('rgba(255,255,255,0.85)');
        $overlay->rectangle($width*0.08, $height*0.55, $width*0.92, $height*0.87);
        $canvas->drawImage($overlay);

        // title
        $draw->setFontSize((int)max(36, $width * 0.034));
        $canvas->annotateImage($draw, $width/2, (int)($height*0.65), 0, $titulo);

        // "Certificamos que"
        $draw->setFontSize((int)max(20, $width * 0.024));
        $draw->setFontWeight(400);
        $canvas->annotateImage($draw, $width/2, (int)($height*0.72), 0, 'Certificamos que');

        // name (larger)
        $draw->setFontSize((int)max(56, $width * 0.06));
        $draw->setFontWeight(700);
        $canvas->annotateImage($draw, $width/2, (int)($height*0.79), 0, $nome);

        // carga and date
        $draw->setFontSize((int)max(20, $width * 0.024));
        $draw->setFontWeight(400);
        $mainText = 'por sua participação no evento "' . $titulo . '"';
        if ($carga) $mainText .= ', com carga horária de ' . $carga . ' horas';
        $mainText .= '.';
        $canvas->annotateImage($draw, $width/2, (int)($height*0.85), 0, $mainText);

        // footer hash small
        $draw->setFontSize(12);
        $draw->setFillColor(new \ImagickPixel('#6b7280'));
        $canvas->annotateImage($draw, 40, $height - 20, 0, 'Código de rastreio: ' . $codigo_hash);

        // output PDF bytes
        $pdfData = $canvas->getImagesBlob();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="certificado.pdf"');
        echo $pdfData;
        exit;
    } catch (Exception $e) {
        // continue
    }
}

// Last fallback: render as UTF-8 HTML then let browser print/save as PDF client-side
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificado (fallback)</title>
<style>
  html,body{height:100%;margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif}
  .tools{max-width:1100px;margin:16px auto;display:flex;justify-content:flex-end;gap:8px;padding:0 20px}
  .btn{background:#2563eb;color:#fff;padding:10px 14px;border-radius:6px;border:0;cursor:pointer}
  .btn-ghost{background:#fff;border:1px solid #e5e7eb;color:#374151;padding:9px 12px;border-radius:6px;cursor:pointer}
  .wrap{max-width:1100px;margin:24px auto;padding:20px}
  .paper{position:relative;width:100%;height:540px;border-radius:10px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.08);background:#fff}
  .paper .bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0;opacity:1}
  .paper .overlay{position:absolute;inset:0;background:linear-gradient(rgba(255,255,255,0.0), rgba(255,255,255,0.0));z-index:1}
  .paper .content{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;padding:40px 80px;text-align:center;color:#0f172a}
  .logo{max-width:160px;margin-bottom:6px;opacity:0.98}
  h1{font-size:26px;margin:8px 0;color:#0b1220;font-weight:700}
  h2{font-size:56px;margin:6px 0;color:#0b1220;font-weight:800}
  p.meta{color:#6b7280;margin-top:16px;font-size:16px}
  .footer{position:absolute;left:20px;bottom:12px;font-size:12px;color:#6b7280;z-index:2}
  @media print{ .tools{display:none} .paper{box-shadow:none;border-radius:0;height:100vh} }
</style>
</head>
<body>
  <div class="tools">
    <button id="downloadPdfBtn" class="btn">Baixar PDF</button>
    <button id="printBtn" class="btn-ghost">Imprimir</button>
  </div>

  <div class="wrap">
    <div class="paper" id="certContainer">
      <?php
        // Gera uma URL de dados base64 para a imagem de fundo para o fallback em HTML
        $bgUrl = '';
        if ($imagem_conteudo) {
            $mime = 'image/jpeg'; // Padrão
            if ($imagem_ext === 'png') $mime = 'image/png';
            $base64 = base64_encode($imagem_conteudo);
            $bgUrl = "data:$mime;base64,$base64";
        }
      ?>
      <?php if ($bgUrl): ?>
        <img src="<?php echo $bgUrl; ?>" alt="Plano de fundo" class="bg" crossorigin="anonymous">
      <?php else: ?>
        <div style="position:absolute;inset:0;background:linear-gradient(180deg,#ffffff,#f8fafc);z-index:0"></div>
      <?php endif; ?>

      <div class="overlay"></div>

      <div class="content">
        <?php if ($bgUrl === ''): ?>
          <?php if (false): // Lógica para logo opcional pode ser inserida aqui ?>
          <?php endif; ?>
        <?php else: ?>
          <!-- show small logo above text if bg has logo area or leave blank -->
        <?php endif; ?>

        <h1><?php echo htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'); ?></h1>
        <h2><?php echo htmlspecialchars($nome, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="meta"><?php
            $mainText = 'por sua participação no evento "' . $titulo . '"';
            if ($carga) $mainText .= ', com carga horária de ' . $carga . ' horas';
            $mainText .= '.';
            echo htmlspecialchars($mainText, ENT_QUOTES, 'UTF-8');
        ?></p>
      </div>

      <div class="footer">Código de rastreio: <?php echo htmlspecialchars($codigo_hash, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
  </div>

  <!-- html2pdf -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
  <script>
    const el = document.getElementById('certContainer');
    function safeFilename(s) { return (s || 'certificado').toLowerCase().replace(/[^a-z0-9_\-]+/g,'_').slice(0,120); }
    document.getElementById('downloadPdfBtn').addEventListener('click', () => {
      const title = safeFilename('<?php echo addslashes($titulo); ?>');
      const name = `certificado_${title}_${safeFilename('<?php echo addslashes($nome); ?>')}.pdf`;
      const opt = {
        margin:       [0.2,0.2,0.2,0.2],
        filename:     name,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false },
        jsPDF:        { unit: 'in', format: 'a4', orientation: 'landscape' }
      };
      const btn = document.getElementById('downloadPdfBtn'); btn.disabled=true; btn.textContent='Gerando PDF...';
      html2pdf().set(opt).from(el).save().then(()=>{ btn.disabled=false; btn.textContent='Baixar PDF'; }).catch(()=>{ btn.disabled=false; btn.textContent='Baixar PDF'; alert('Falha ao gerar PDF'); });
    });

    document.getElementById('printBtn').addEventListener('click', () => {
      const w = window.open('', '_blank');
      w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Imprimir certificado</title><style>body{margin:0;padding:40px;background:#fff;font-family:Arial,Helvetica,sans-serif}</style></head><body>' + document.getElementById('certContainer').outerHTML + '</body></html>');
      w.document.close(); setTimeout(()=>w.print(),400);
    });

  </script>
</body>
</html>
<?php
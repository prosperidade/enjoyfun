<?php
/**
 * EnjoyFun 2.0 — EmailService
 * Integração com Resend (https://resend.com) via cURL
 */

namespace EnjoyFun\Services;

class EmailService
{
    /**
     * Envia um e-mail com código OTP via Resend API.
     *
     * @param string $toEmail   Destinatário
     * @param string $code      Código OTP de 6 dígitos
     * @param string $apiKey    Resend API Key do organizador (re_xxxxxxxxxx)
     * @param string $fromEmail Remetente configurado pelo organizador
     * @return bool             true em caso de sucesso
     */
    public static function sendOTP(
        string $toEmail,
        string $code,
        string $apiKey,
        string $fromEmail = 'no-reply@enjoyfun.com.br'
    ): bool {
        if (!extension_loaded('curl')) {
            error_log('[EmailService] ERRO: Extensão cURL não está instalada no PHP.');
            return false; // Deixa o caller retornar 500 adequado
        }

        $html = self::buildOTPHtml($code);

        $payload = json_encode([
            'from'    => "EnjoyFun <{$fromEmail}>",
            'to'      => [$toEmail],
            'subject' => "Seu código de acesso EnjoyFun: {$code}",
            'html'    => $html,
        ]);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            error_log("[EmailService] OTP enviado para {$toEmail}");
            return true;
        }

        error_log("[EmailService] Falha ao enviar OTP. Status: {$status} | Body: {$response}");
        return false;
    }

    // ─────────────────────────────────────────────────────────────
    // HTML Template — Dark, premium, EnjoyFun branding
    // ─────────────────────────────────────────────────────────────
    private static function buildOTPHtml(string $code): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Seu código EnjoyFun</title>
</head>
<body style="margin:0;padding:0;background:#030712;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#030712;">
    <tr><td align="center" style="padding:40px 16px;">
      <table width="100%" style="max-width:480px;background:#0f172a;border-radius:20px;border:1px solid #1e293b;overflow:hidden;">

        <!-- Header gradient -->
        <tr><td style="background:linear-gradient(135deg,#7c3aed,#db2777);padding:32px 32px 24px;text-align:center;">
          <div style="width:56px;height:56px;background:rgba(255,255,255,0.15);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;">
            <span style="font-size:28px;">⚡</span>
          </div>
          <h1 style="margin:0;color:#fff;font-size:22px;font-weight:800;letter-spacing:-0.5px;">EnjoyFun</h1>
          <p style="margin:6px 0 0;color:rgba(255,255,255,0.7);font-size:13px;">Plataforma de Eventos</p>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:32px;">
          <p style="margin:0 0 8px;color:#94a3b8;font-size:14px;">Olá! Seu código de acesso é:</p>

          <!-- OTP code big -->
          <div style="background:#1e293b;border:1px solid #334155;border-radius:16px;padding:28px;text-align:center;margin:20px 0;">
            <span style="font-size:48px;font-weight:900;letter-spacing:12px;color:#fff;font-family:'Courier New',monospace;">{$code}</span>
          </div>

          <p style="margin:0 0 8px;color:#64748b;font-size:13px;line-height:1.6;">
            Este código expira em <strong style="color:#94a3b8;">10 minutos</strong>.<br>
            Se você não solicitou este código, ignore este e-mail.
          </p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="border-top:1px solid #1e293b;padding:20px 32px;text-align:center;">
          <p style="margin:0;color:#334155;font-size:11px;">
            © {$GLOBALS['year']} EnjoyFun · Este é um e-mail automático, não responda.
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────
    // Envio de e-mail avulso (mensagem manual pelo Organizador)
    // ─────────────────────────────────────────────────────────────
    /**
     * @param string $to       Destinatário
     * @param string $subject  Assunto do e-mail
     * @param string $content  Corpo em texto simples (será envolvido em HTML)
     * @param string $apiKey   Resend API Key do organizador
     * @param string $from     E-mail remetente
     */
    public static function sendManualEmail(
        string $to,
        string $subject,
        string $content,
        string $apiKey,
        string $from = 'no-reply@enjoyfun.com.br'
    ): bool {
        if (!extension_loaded('curl')) {
            error_log('[EmailService] ERRO: Extensão cURL não está instalada no PHP.');
            return false;
        }

        $year    = $GLOBALS['year'] ?? date('Y');
        $safeMsg = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#030712;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#030712;">
    <tr><td align="center" style="padding:40px 16px;">
      <table width="100%" style="max-width:480px;background:#0f172a;border-radius:20px;border:1px solid #1e293b;overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,#7c3aed,#db2777);padding:24px 32px;text-align:center;">
          <h1 style="margin:0;color:#fff;font-size:20px;font-weight:800;">⚡ EnjoyFun</h1>
        </td></tr>
        <tr><td style="padding:32px;">
          <p style="margin:0;color:#cbd5e1;font-size:14px;line-height:1.7;">{$safeMsg}</p>
        </td></tr>
        <tr><td style="border-top:1px solid #1e293b;padding:16px 32px;text-align:center;">
          <p style="margin:0;color:#334155;font-size:11px;">© {$year} EnjoyFun · Plataforma de Eventos</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

        $payload = json_encode([
            // Resend aceita tanto "Nome <email>" quanto só "email".
            // Para domínios não verificados (onboarding@resend.dev), usar só o e-mail evita rejeição.
            'from'    => $from,    // ex: "onboarding@resend.dev" — sem display name
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ]);

        error_log("[DEBUG RESEND] Payload: " . json_encode(['from' => $from, 'to' => $to, 'subject' => $subject]));

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            error_log("[EmailService] E-mail manual enviado para {$to}");
            return true;
        }

        // Log completo + lança exceção para que o controller exponha ao frontend
        $debugMsg = "[DEBUG RESEND] Status: {$status} | Resposta: {$response}";
        error_log($debugMsg);
        throw new \RuntimeException("Resend HTTP {$status}: {$response}");
    }
}

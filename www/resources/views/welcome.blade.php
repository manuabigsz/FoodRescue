<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="FoodRescue conecta excedentes agrícolas a compradores e organizações sociais com pagamentos e provas na Solana.">
        <meta name="theme-color" content="#123c2d">
        <title>FoodRescue — alimento com destino</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <a class="skip-link" href="#main-content">Pular para o conteúdo</a>
        <header class="site-header" id="site-header">
            <a class="brand" href="#/" aria-label="FoodRescue — início">
                <span class="brand-mark" aria-hidden="true"><img src="/images/foodrescue-logo.png" alt=""></span>
                <span>Food<span>Rescue</span></span>
            </a>
            <nav class="desktop-nav" aria-label="Navegação principal">
                <a href="#/catalogo" data-nav="catalogo">Excedentes</a>
                <a href="#/doacoes" data-nav="doacoes">Doações</a>
                <a href="#/acompanhamento" data-nav="acompanhamento">Acompanhar</a>
                <a href="#/rede" data-nav="rede">Solana</a>
            </nav>
            <div class="header-actions">
                <span class="network-chip"><i></i> Devnet</span>
                <button class="button button-ghost wallet-button" type="button" data-open-auth>Conectar carteira</button>
                <button class="menu-button" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="Abrir menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            </div>
        </header>
        <nav class="mobile-nav" id="mobile-nav" aria-label="Navegação móvel" hidden>
            <a href="#/catalogo">Excedentes</a><a href="#/doacoes">Doações</a><a href="#/acompanhamento">Acompanhar</a><a href="#/rede">Solana</a><a href="#/dashboard">Meu painel</a>
        </nav>
        <main id="main-content" tabindex="-1" aria-live="polite"></main>
        <footer class="site-footer">
            <div><a class="brand brand-footer" href="#/"><span class="brand-mark" aria-hidden="true"><img src="/images/foodrescue-logo.png" alt=""></span><span>Food<span>Rescue</span></span></a><p>Menos desperdício. Mais valor e impacto verificável.</p></div>
            <div class="footer-links"><a href="#/catalogo">Marketplace</a><a href="#/doacoes">Impacto social</a><a href="#/rede">Transparência on-chain</a></div>
            <p class="footer-note">MVP em Solana Devnet. FRUSD não representa moeda fiduciária real.</p>
        </footer>
        <div class="modal-backdrop" data-auth-modal hidden>
            <section class="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-title">
                <button class="modal-close" type="button" data-close-auth aria-label="Fechar">×</button>
                <div class="auth-visual">
                    <span class="eyebrow eyebrow-light">ACESSO SEGURO</span><h2 id="auth-title">Sua identidade começa na carteira.</h2>
                    <p>O FoodRescue solicita apenas sua chave pública e uma assinatura de comprovação. Nunca pediremos sua seed phrase ou chave privada.</p>
                    <div class="safe-note"><span>✓</span> A assinatura não movimenta fundos.</div>
                </div>
                <div class="auth-content">
                    <div class="auth-tabs" role="tablist"><button class="active" type="button" role="tab" aria-selected="true" data-auth-tab="login">Entrar</button><button type="button" role="tab" aria-selected="false" data-auth-tab="register">Criar conta</button></div>
                    <div data-auth-panel="login"></div><div data-auth-panel="register" hidden></div>
                </div>
            </section>
        </div>
        <div class="toast-region" aria-live="assertive" aria-atomic="true"></div>
    </body>
</html>

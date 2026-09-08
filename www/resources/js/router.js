import { paintNavigation } from './core/navigation.js';
import { renderAdmin } from './pages/admin.js';
import { renderCatalog } from './pages/catalog.js';
import { renderDashboard } from './pages/dashboard.js';
import { renderDonations } from './pages/donations.js';
import { renderFreights } from './pages/freights.js';
import { renderLanding } from './pages/landing.js';
import { renderMyLots } from './pages/my-lots.js';
import { renderNetwork } from './pages/network.js';
import { renderProfile } from './pages/profile.js';
import { renderPublish } from './pages/publish.js';
import { renderReputation } from './pages/reputation.js';
import { renderTracking } from './pages/tracking.js';

export function route() {
    const path = location.hash.replace(/^#\/?/, '').split('?')[0] || '';
    paintNavigation();
    document.querySelector('#mobile-nav').hidden = true;
    document.querySelector('.menu-button').setAttribute('aria-expanded', 'false');
    if (path === 'catalogo') return renderCatalog();
    if (path === 'dashboard') return renderDashboard();
    if (path === 'acompanhamento') return renderTracking();
    if (path === 'publicar') return renderPublish();
    if (path === 'fretes') return renderFreights();
    if (path === 'meus-lotes') return renderMyLots();
    if (path === 'perfil') return renderProfile();
    if (path === 'reputacao') return renderReputation();
    if (path === 'admin') return renderAdmin();
    if (path === 'doacoes') return renderDonations();
    if (path === 'rede') return renderNetwork();
    renderLanding();
}

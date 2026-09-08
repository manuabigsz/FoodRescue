import { closeAuth, openAuthOrDashboard, selectAuthTab } from './auth/modal.js';
import { bootSession, handleLogout, updateSessionUi } from './core/session.js';
import { modal } from './core/ui.js';
import { route } from './router.js';

document.querySelector('.menu-button').addEventListener('click', function (event) {
    const nav = document.querySelector('#mobile-nav');
    nav.hidden = !nav.hidden;
    event.currentTarget.setAttribute('aria-expanded', String(!nav.hidden));
});
document.querySelectorAll('[data-open-auth]').forEach(function (button) { button.addEventListener('click', function () { openAuthOrDashboard('login'); }); });
document.querySelectorAll('[data-logout]').forEach(function (button) { button.addEventListener('click', handleLogout); });
document.querySelector('[data-close-auth]').addEventListener('click', closeAuth);
modal.addEventListener('click', function (event) { if (event.target === modal) closeAuth(); });
document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeAuth(); });
document.querySelectorAll('[data-auth-tab]').forEach(function (button) { button.addEventListener('click', function () { selectAuthTab(button.dataset.authTab); }); });
window.addEventListener('hashchange', route);
updateSessionUi();
route();
bootSession().then(function (changed) { if (changed) route(); });

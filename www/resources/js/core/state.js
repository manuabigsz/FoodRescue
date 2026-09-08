import { selectedTradeId } from '../pages/tracking.js';

export const state = {
    token: sessionStorage.getItem('foodrescue_token'),
    tokenExpiresAt: sessionStorage.getItem('foodrescue_token_expires_at'),
    user: JSON.parse(sessionStorage.getItem('foodrescue_user') || 'null'),
    wallet: null,
    dashboardRole: 'producer',
    catalog: [],
    catalogMeta: null,
    catalogFilters: { search: '', sort: 'urgency', donation_eligible: '', per_page: 12, page: 1 },
    catalogReason: null,
    catalogError: false,
    catalogFilterTouched: false,
    trades: [],
    selectedTradeId: null,
    trackingPanel: null,
    referenceCatalog: null,
};

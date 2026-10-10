import { initAccountLists } from './account-list';
import { initCustomers } from './customers';
import { initLive } from './live';
import { initPoster } from './poster';
import { initSegmentation } from './segmentation';
import { initTabs } from './tabs';
import { initTooltips } from './tooltip';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initTooltips();
    initSegmentation();
    initPoster();
    initLive();
    initCustomers();
    initAccountLists();
});

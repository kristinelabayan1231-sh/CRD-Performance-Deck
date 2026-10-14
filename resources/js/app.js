import { initAccountLists } from './account-list';
import { initCraOrders } from './cra-orders';
import { initCustomers } from './customers';
import { initLive } from './live';
import { initMonthlyCost } from './monthly-cost';
import { initProductGroups } from './pc-report';
import { initPoster } from './poster';
import { initResultsFlip } from './results-flip';
import { initSegmentation } from './segmentation';
import { initTabs } from './tabs';
import { initTooltips } from './tooltip';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initTooltips();
    initSegmentation();
    initPoster();
    initResultsFlip();
    initLive();
    initCustomers();
    initAccountLists();
    initCraOrders();
    initMonthlyCost();
    initProductGroups();
});

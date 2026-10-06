import { initSegmentation } from './segmentation';
import { initTabs } from './tabs';
import { initTooltips } from './tooltip';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initTooltips();
    initSegmentation();
});

import { initSegmentation } from './segmentation';
import { initTabs } from './tabs';

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initSegmentation();
});

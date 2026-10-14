// Settings → Product groups. Preview: nothing here is saved yet; changes stay on the screen.

// Settings → Pancake Pages: group the pages that sell the same product.
export function initProductGroups() {
    const root = document.querySelector('[data-product-groups]');
    if (!root) return;

    const list = root.querySelector('[data-group-list]');
    const free = root.querySelector('[data-group-free]');
    const template = root.querySelector('[data-group-template]');

    const chip = (page, group) => {
        const span = document.createElement('span');
        span.dataset.page = page;
        span.className = 'inline-flex items-center gap-1 rounded-full border border-line bg-canvas/60 py-0.5 pl-3 text-xs font-medium ' + (group ? 'pr-1' : 'pr-3');
        span.textContent = page;
        if (group) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.groupUnpage = '';
            remove.className = 'grid size-5 place-items-center rounded-full text-muted hover:bg-coral/15 hover:text-coral-700';
            remove.setAttribute('aria-label', `Take ${page} out of ${group}`);
            remove.textContent = '×';
            span.append(remove);
        }
        return span;
    };

    // Counts, "no pages" notes and every group's page picker follow the chips on screen.
    const refresh = () => {
        const freePages = [...free.querySelectorAll('[data-page]')].map((el) => el.dataset.page);
        root.querySelectorAll('[data-group-list] [data-group]').forEach((group) => {
            const count = group.querySelectorAll('[data-group-pages] [data-page]').length;
            group.querySelector('[data-group-count]').textContent = count;
            group.querySelector('[data-group-rows]').textContent = count === 1 ? 'row' : 'rows';
            group.querySelector('[data-group-none]').classList.toggle('hidden', count > 0);
            const select = group.querySelector('[data-group-page]');
            select.replaceChildren(new Option(freePages.length ? '+ Add page' : 'No pages left', ''), ...freePages.map((page) => new Option(page, page)));
        });
        root.querySelector('[data-group-empty]').classList.toggle('hidden', list.children.length > 0);
        root.querySelector('[data-group-free-empty]').classList.toggle('hidden', freePages.length > 0);
    };

    root.querySelector('[data-group-add]').addEventListener('submit', (event) => {
        event.preventDefault();
        const input = root.querySelector('[data-group-name]');
        const name = input.value.trim();
        if (!name) return;

        const group = template.content.firstElementChild.cloneNode(true);
        group.querySelector('[data-group-title]').textContent = name;
        list.append(group);
        input.value = '';
        refresh();
    });

    root.addEventListener('click', (event) => {
        const unpage = event.target.closest('[data-group-unpage]');
        if (unpage) {
            const page = unpage.closest('[data-page]');
            free.append(chip(page.dataset.page));
            page.remove();
            return refresh();
        }

        const remove = event.target.closest('[data-group-remove]');
        if (remove) {
            const group = remove.closest('[data-group]');
            group.querySelectorAll('[data-group-pages] [data-page]').forEach((page) => free.append(chip(page.dataset.page)));
            group.remove();
            refresh();
        }
    });

    root.addEventListener('change', (event) => {
        const select = event.target.closest('[data-group-page]');
        if (!select?.value) return;

        const group = select.closest('[data-group]');
        group.querySelector('[data-group-none]').before(chip(select.value, group.querySelector('[data-group-title]').textContent));
        free.querySelector(`[data-page="${CSS.escape(select.value)}"]`)?.remove();
        refresh();
    });

    refresh();
}

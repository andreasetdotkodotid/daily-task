const filters = document.querySelectorAll('.filter');
const tasks = document.querySelectorAll('.task');
const form = document.querySelector('#taskForm');
const title = document.querySelector('#title');
const themeOptions = document.querySelectorAll('.theme-option');

const getStoredTheme = () => {
    try {
        return localStorage.getItem('dailyTaskTheme') || 'default';
    } catch (error) {
        return 'default';
    }
};

const setStoredTheme = (theme) => {
    try {
        localStorage.setItem('dailyTaskTheme', theme);
    } catch (error) {
        // Theme preference is cosmetic; ignore storage failures.
    }
};

const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    setStoredTheme(theme);

    themeOptions.forEach((button) => {
        const isActive = button.dataset.theme === theme;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', String(isActive));
    });
};

applyTheme(getStoredTheme());

themeOptions.forEach((button) => {
    button.addEventListener('click', () => {
        applyTheme(button.dataset.theme || 'default');
    });
});

filters.forEach((button) => {
    button.addEventListener('click', () => {
        const filter = button.dataset.filter;

        filters.forEach((item) => item.classList.remove('active'));
        button.classList.add('active');

        tasks.forEach((task) => {
            const visible = filter === 'all' || task.dataset.status === filter;
            task.classList.toggle('is-hidden', !visible);
        });
    });
});

form?.addEventListener('submit', (event) => {
    if (title.value.trim().length === 0) {
        event.preventDefault();
        title.focus();
    }
});

document.querySelectorAll('.delete-form').forEach((formElement) => {
    formElement.addEventListener('submit', (event) => {
        if (!window.confirm('Hapus task ini?')) {
            event.preventDefault();
        }
    });
});

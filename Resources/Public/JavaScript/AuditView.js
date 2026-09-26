// Show event timestamps (rendered as UTC) in the browser's local timezone
document.addEventListener('DOMContentLoaded', () => {
    const formatter = new Intl.DateTimeFormat(undefined, {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
    });
    document.querySelectorAll('time[data-auditview-localize]').forEach((element) => {
        const date = new Date(element.getAttribute('datetime'));
        if (!isNaN(date.getTime())) {
            element.title = element.textContent;
            element.textContent = formatter.format(date);
        }
    });
});

// Filter dropdowns (see Dropdown.fusion): keep group and option checkboxes in sync and update the summary.
// A checked group is submitted as a whole (its options are resolved on the server), so its option checkboxes
// are shown checked but disabled. Groups without a group checkbox are plain headings.
// Single-select dropdowns use radio buttons and close after choosing.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-auditview-dropdown]').forEach((dropdown) => {
        const summary = dropdown.querySelector('summary');
        const groups = Array.from(dropdown.querySelectorAll('.sandstorm-auditview__dropdown-group'));
        const radios = Array.from(dropdown.querySelectorAll('input[type="radio"][data-auditview-option]'));

        const updateSummary = () => {
            if (radios.length > 0) {
                const checked = radios.find((radio) => radio.checked);
                summary.textContent = checked && checked.value !== '' ? checked.dataset.label : dropdown.dataset.allLabel;
                return;
            }
            const parts = groups.map((group) => {
                const groupCheckbox = group.querySelector('[data-auditview-group]');
                const optionCheckboxes = Array.from(group.querySelectorAll('[data-auditview-option]'));
                const selectedOptions = optionCheckboxes.filter((checkbox) => checkbox.checked);
                if (!groupCheckbox) {
                    return selectedOptions.length > 0 ? selectedOptions.map((checkbox) => checkbox.dataset.label).join(', ') : null;
                }
                groupCheckbox.indeterminate = !groupCheckbox.checked && selectedOptions.length > 0;
                if (groupCheckbox.checked) {
                    return group.dataset.groupLabel;
                }
                return selectedOptions.length > 0 ? `${group.dataset.groupLabel} (${selectedOptions.length}/${optionCheckboxes.length})` : null;
            }).filter((part) => part !== null);
            summary.textContent = parts.length > 0 ? parts.join(', ') : dropdown.dataset.allLabel;
        };

        radios.forEach((radio) => {
            radio.addEventListener('change', () => {
                updateSummary();
                dropdown.open = false;
            });
        });

        groups.forEach((group) => {
            const groupCheckbox = group.querySelector('[data-auditview-group]');
            const optionCheckboxes = Array.from(group.querySelectorAll('input[type="checkbox"][data-auditview-option]'));

            optionCheckboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', updateSummary);
            });
            if (!groupCheckbox) {
                return;
            }
            groupCheckbox.addEventListener('change', () => {
                optionCheckboxes.forEach((checkbox) => {
                    checkbox.checked = groupCheckbox.checked;
                    checkbox.disabled = groupCheckbox.checked;
                });
                updateSummary();
            });
            // a disabled checkbox can't be clicked: clicking its label while the group is checked
            // de-selects the group, keeping all other options of the group selected
            optionCheckboxes.forEach((checkbox) => {
                checkbox.closest('label').addEventListener('click', (event) => {
                    if (!groupCheckbox.checked) {
                        return;
                    }
                    event.preventDefault();
                    groupCheckbox.checked = false;
                    optionCheckboxes.forEach((other) => {
                        other.disabled = false;
                        other.checked = other !== checkbox;
                    });
                    updateSummary();
                });
            });
        });

        updateSummary();

        // only one dropdown open at a time; close when clicking outside
        dropdown.addEventListener('toggle', () => {
            if (dropdown.open) {
                document.querySelectorAll('[data-auditview-dropdown][open]').forEach((other) => {
                    if (other !== dropdown) {
                        other.open = false;
                    }
                });
            }
        });
        document.addEventListener('click', (event) => {
            if (dropdown.open && !dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });
});

// "Go to" navigation (see GoTo.fusion): send the timezone offset of the picked local time (DST-aware) along,
// and scroll the source event into view after a jump ("load more" links carry a #fragment instead)
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-auditview-gototime]').forEach((form) => {
        form.addEventListener('submit', () => {
            const date = new Date(form.querySelector('input[type="datetime-local"]').value);
            if (!isNaN(date.getTime())) {
                form.querySelector('input[name="moduleArguments[timezoneOffset]"]').value = String(date.getTimezoneOffset());
            }
        });
    });
    const sourceEvent = document.querySelector('[data-auditview-source]');
    if (sourceEvent && !window.location.hash) {
        sourceEvent.scrollIntoView({ block: 'center' });
    }
});

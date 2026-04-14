(function() {
    const config = window.localCourseExamsConfig || {};
    const form = document.getElementById('local-courseexams-form');
    const input = document.getElementById('local-courseexams-courseid');
    const statusNode = document.getElementById('local-courseexams-status');
    const summaryNode = document.getElementById('local-courseexams-summary');
    const listNode = document.getElementById('local-courseexams-list');
    let currentCourseId = Number(config.initialcourseid || 0);
    let timer = null;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const renderState = (type, message) => {
        statusNode.innerHTML = message ? `<div class="local-courseexams-${type}">${escapeHtml(message)}</div>` : '';
    };

    const renderSummary = (data) => {
        const stats = [
            ['Course ID', data.course.id],
            ['Exams', data.summary.totalexams],
            ['Assignments', data.summary.assigncount],
            ['Quizzes', data.summary.quizcount],
            ['Visible', data.summary.visiblecount],
            ['Hidden', data.summary.hiddencount],
            ['Overrides', data.summary.overridecount],
            ['Questions', data.summary.quizquestioncount],
        ];

        summaryNode.innerHTML = stats.map(([label, value]) => `
            <article class="local-courseexams-stat">
                <span class="local-courseexams-stat-label">${escapeHtml(label)}</span>
                <span class="local-courseexams-stat-value">${escapeHtml(value)}</span>
            </article>
        `).join('');
    };

    const renderRows = (rows, columns) => {
        if (!rows.length) {
            return `<p class="local-courseexams-muted">${escapeHtml(config.strings.empty || 'No data')}</p>`;
        }

        const head = columns.map((column) => `<th>${escapeHtml(column.label)}</th>`).join('');
        const body = rows.map((row) => `
            <tr>${columns.map((column) => `<td>${escapeHtml(row[column.key] ?? '')}</td>`).join('')}</tr>
        `).join('');

        return `<table class="local-courseexams-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
    };

    const renderExamCard = (exam) => {
        const details = exam.type === 'quiz'
            ? `
                <details class="local-courseexams-details">
                    <summary>Questions (${exam.questions.length})</summary>
                    <div class="local-courseexams-details-content">
                        ${renderRows(exam.questions, [
                            { key: 'slot', label: 'Slot' },
                            { key: 'displayednumber', label: 'Number' },
                            { key: 'name', label: 'Question' },
                            { key: 'qtype', label: 'Type' },
                            { key: 'maxmark', label: 'Max mark' },
                            { key: 'page', label: 'Page' },
                        ])}
                    </div>
                </details>
            `
            : '';

        const overrideBlocks = exam.overrides.details.map((block) => `
            <details class="local-courseexams-details">
                <summary>${escapeHtml(block.title)} (${block.count})</summary>
                <div class="local-courseexams-details-content">
                    ${renderRows(block.items, block.columns)}
                </div>
            </details>
        `).join('');

        return `
            <article class="local-courseexams-card">
                <div class="local-courseexams-card-header">
                    <div>
                        <h3 class="local-courseexams-card-title">${escapeHtml(exam.name)}</h3>
                        <div class="local-courseexams-chip-row">
                            <span class="local-courseexams-chip">${escapeHtml(exam.type_label)}</span>
                            <span class="local-courseexams-chip">${escapeHtml(exam.section.label)}</span>
                            <span class="local-courseexams-chip ${exam.visible ? '' : 'hidden'}">${escapeHtml(exam.visible_label)}</span>
                        </div>
                    </div>
                    <div class="local-courseexams-muted">CMID ${escapeHtml(exam.cmid)} | Instance ${escapeHtml(exam.instanceid)}</div>
                </div>
                <div class="local-courseexams-card-body">
                    <div class="local-courseexams-meta">
                        ${exam.meta.map((item) => `
                            <div class="local-courseexams-meta-item">
                                <span class="local-courseexams-meta-label">${escapeHtml(item.label)}</span>
                                <span class="local-courseexams-meta-value">${escapeHtml(item.value)}</span>
                            </div>
                        `).join('')}
                    </div>
                    <div class="local-courseexams-chip-row">
                        <span class="local-courseexams-chip">Overrides: ${escapeHtml(exam.overrides.total)}</span>
                        ${exam.overrides.summary.map((item) => `<span class="local-courseexams-chip">${escapeHtml(item)}</span>`).join('')}
                    </div>
                    ${overrideBlocks}
                    ${details}
                </div>
            </article>
        `;
    };

    const renderData = (data) => {
        renderState('note', `Refreshed at ${data.generated.label}`);
        renderSummary(data);
        listNode.innerHTML = data.exams.length ? data.exams.map(renderExamCard).join('') : `<div class="local-courseexams-note">${escapeHtml(config.strings.empty || 'No data')}</div>`;
    };

    const loadCourse = async (courseId) => {
        if (!courseId || Number.isNaN(courseId) || courseId < 1) {
            renderState('error', config.strings.invalidcourseid || 'Invalid course id');
            summaryNode.innerHTML = '';
            listNode.innerHTML = '';
            return;
        }

        renderState('note', config.strings.loading || 'Loading');

        const body = new URLSearchParams({
            sesskey: config.sesskey,
            courseid: String(courseId),
        });

        try {
            const response = await fetch(config.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body,
            });
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'Request failed');
            }

            renderData(payload.data);
        } catch (error) {
            summaryNode.innerHTML = '';
            listNode.innerHTML = '';
            renderState('error', error.message || 'Request failed');
        }
    };

    const restartTimer = () => {
        if (timer) {
            window.clearInterval(timer);
        }
        if (currentCourseId > 0) {
            timer = window.setInterval(() => loadCourse(currentCourseId), Number(config.pollintervalms || 20000));
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        currentCourseId = Number(input.value);
        const url = new URL(window.location.href);
        url.searchParams.set('courseid', String(currentCourseId));
        window.history.replaceState({}, '', url);
        loadCourse(currentCourseId);
        restartTimer();
    });

    if (currentCourseId > 0) {
        loadCourse(currentCourseId);
        restartTimer();
    }
})();

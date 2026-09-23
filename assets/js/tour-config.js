// assets/js/tour-config.js
// Step-by-step tour definitions, keyed by the same "page_key" that's stored
// in user_page_tours and sent to api/tour_status.php. Add a new page's tour
// by adding an entry here, giving the page a matching
// <body data-tour-page="..."> and dropping in the two <script> tags that
// load tour-config.js and tour.js (see any page below for the pattern).
//
// Each step: { selector, title, text }. A selector that matches nothing (or
// nothing visible) on the current page is skipped automatically — no need to
// guard for missing permissions or empty tables here.
(function () {
  window.ASTRA_TOUR_CONFIGS = {

    'client/client_portal': [
      {
        selector: '#tour-submit-requirement',
        title: 'Submit a requirement',
        text: 'Start a new project or raise a change request against a completed one. Your Project Manager is the only one who can submit these.'
      },
      {
        selector: '#tour-my-requirements',
        title: 'Status pipeline',
        text: 'Track every requirement you’ve submitted as it moves from Pending Review through Approved. You can also withdraw one while it’s still pending.'
      },
      {
        selector: '#tour-my-projects',
        title: 'Projects table',
        text: 'Once a requirement is approved and staffed, it shows up here with live task and bug progress, plus a place to leave comments for the team.'
      },
      {
        selector: '#tour-delivery-billing',
        title: 'Invoices & payments',
        text: 'When a project is delivered, its invoice and handover links land here. Pay an invoice to unlock the one-time security summary for that project.'
      }
    ],

    'admin/admin_portal': [
      {
        selector: '#tour-requirements',
        title: 'Requirement reviews',
        text: 'New client requirements start here. Approve, reject or ask for clarification. The client is emailed automatically either way.'
      },
      {
        selector: '#tour-projects-link',
        title: 'Project assignment',
        text: 'Turn an approved requirement into a project: pick a team lead and staff developers, testers and security testers onto it.'
      },
      {
        selector: '#tour-delivery',
        title: 'Delivery & AI docs',
        text: 'Once a project is complete, hand it over here with source, docs and deployment links, and generate an AI-drafted documentation page for the client.'
      },
      {
        selector: '#tour-billing',
        title: 'Billing generator',
        text: 'Turn completed projects into invoices from dev hours, hourly rate and infra cost, then mark them paid once the client settles up.'
      }
    ],

    'emlpoyee/employee_portal': [
      {
        selector: '#tour-my-tasks-card',
        title: 'My tasks',
        text: 'Everything assigned to you across every project lives here. Update status and attach evidence files as you go.'
      },
      {
        selector: '#tour-team-lead-card',
        title: 'Team lead panel',
        text: 'You lead at least one project, so this panel is yours too: create and assign tasks, and request deployment once everything’s green.'
      }
    ],

    'emlpoyee/my_tasks': [
      {
        selector: '#sec-my-tasks',
        title: 'My tasks table',
        text: 'Every task assigned to you, ordered by priority. Each row shows the project it belongs to, its deadline and who assigned it.'
      },
      {
        selector: '.status-select',
        title: 'Task status dropdown',
        text: 'Move a task through Pending → In Progress → Completed (or flag it Blocked) right from the table.'
      },
      {
        selector: '.drop-zone',
        title: 'Evidence drag & drop',
        text: 'Click or drag a file onto a task to attach evidence: screenshots, docs, anything up to 10MB. Your team lead can see it too.'
      }
    ],

    'emlpoyee/testing_portal': [
      {
        selector: '#sec-report-bug .btn-report',
        title: 'Report bug button',
        text: 'Log a functional, UI, performance or security issue against any task in a project you’re testing, and assign it straight to a developer.'
      },
      {
        selector: '#vulnClassSelect',
        title: 'Vulnerability class dropdown',
        text: 'For security bugs, classify the finding (SQLi, XSS, CSRF, auth bypass…) and Astra suggests the matching CWE ID for you.'
      },
      {
        selector: '#sec-bugs-reported',
        title: 'Retest workflow',
        text: 'When a developer marks a bug Fixed, it lands here for you to retest. Confirm and close it, or reopen it if the fix didn’t hold.'
      }
    ],

    'emlpoyee/deployment_portal': [
      {
        selector: '#sec-ready-deploy',
        title: 'Ready to deploy list',
        text: 'Projects show up here once every task is completed and every bug is closed or accepted, so it is ready for you to request release.'
      },
      {
        selector: 'input[name="repo_url"]',
        title: 'GitHub repo link',
        text: 'Paste the project’s public GitHub repository. The AI documentation generator reads it to write the handover docs.'
      },
      {
        selector: 'textarea[name="notes"]',
        title: 'Deployment notes',
        text: 'Summarise what was built and tested for the admin reviewing the request. This note travels with the approval.'
      }
    ]

  };
})();

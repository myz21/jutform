Candidate Guide
Overview
JutForm is a fictional form builder application — a parody inspired by Jotform, built solely for
this assessment.
You will receive a fully functional local development environment, a set of support tickets
describing problems that users are experiencing and a set of feature requests. Your job is to
investigate, debug, and fix these issues or implement requested features — just like you
would on day one at a software engineering role.
Format
• Duration: 3 hours
• Work style: Individual (no collaboration with other candidates)
• Support Tickets: You will receive a set of support tickets of varying difficulty and type
• Feature Requests: You will also receive a set of feature implementation tasks of
varying complexity
• Deliverable: Your fixes and implementations, committed and pushed to your private
GitHub repository
Support tickets are written from the perspective of non-technical users and internal
teams — translating vague user reports into concrete technical investigations is part of the
challenge.
What You'll Work With
• A PHP backend application (no framework — custom legacy codebase)
• A MySQL 8 database pre-loaded with realistic data
• A Redis instance used for caching and async job processing
• A pre-built React frontend (the frontend is not part of the assessment — you won't
need to modify it, but you can inspect its network requests in your browser's DevTools)
• A Docker Compose environment that runs everything locally on your machine
You will also have access to:
• Application logs and error logs
• Database access (direct MySQL CLI)
• An email capture interface (to inspect outgoing emails)
• System status pages (PHP-FPM pool status, queue depth)
• An admin panel with a basic log viewer
Prerequisites
Please have the following ready before the event:
Windows Users: WSL Is Strongly Recommended
If you are on Windows, we strongly recommend using WSL (Windows Subsystem for
Linux) as your development environment. The assessment involves Docker, shell scripts,
and command-line tools that work most reliably in a Linux environment.
1. Install WSL: Follow the official guide at Install WSL
2. Set up Docker with WSL: Follow Get started with Docker containers on WSL
After setup, open an Ubuntu terminal (search "Ubuntu" in the Start menu) and do all your
work from there — cloning repos, running Docker, editing code, etc.
Required Software
1. An environment capable of running docker and docker compose (e.g., Docker
Desktop with WSL on Windows, Colima, Podman, or native Docker Engine on Linux)
• Verify it works: run docker run hello-world in your terminal
• Ensure Docker Compose is available: run docker compose version
2. Git — Any recent version
3. A GitHub account — You will create a private repository from a template
4. A code editor of your choice (VS Code, PhpStorm, Vim, etc.)
Important: Git Proficiency
All your work must be committed and pushed to your GitHub repository. We evaluate
your submissions solely based on what is in your repo — uncommitted local changes will
not be considered.
If you are not comfortable with Git, please take some time before the event to practice the
basics. At a minimum, you should be able to:
• git add — stage your changes
• git commit -m "message" — save a snapshot with a descriptive message
• git push — upload your commits to GitHub
We strongly recommend practicing these commands beforehand so you can focus on
solving problems during the assessment rather than wrestling with version control. A short
tutorial like git - the simple guide covers everything you'll need.
Recommended (Not Required)
• A MySQL client (e.g., DBeaver, DataGrip, or mysql CLI)
• A REST client (e.g., Postman, Insomnia, or curl )
• Basic familiarity with PHP, MySQL, Redis, and Docker
Topics Worth Reviewing Beforehand
The assessment covers the kinds of problems and tasks you would encounter in a real
backend engineering role. You do not need to prepare for any specific scenario, but
brushing up on the following areas will serve you well:
• MySQL — indexes and EXPLAIN , aggregation queries ( GROUP BY , SUM , AVG ),
pagination patterns, prepared statements
• Redis — basic data structures, TTL and key expiry, atomic operations
• HTTP — standard status codes and when to use each, common response headers
( Content-Type , Content-Disposition , Retry-After )
• Security fundamentals — SQL injection prevention, server-side request forgery
(SSRF), safe input validation
• Middleware patterns — how request/response middleware pipelines work
conceptually
Pre-Event Verification (Important)
We have prepared a pre-flight check repository that verifies your machine can run the
assessment environment: https://github.com/mehmetcozdemir/jutform-preflight
Clone the repo and follow the instructions in its README. Please complete this before
the event. Environment issues that surface on event day will be very difficult to
troubleshoot under time pressure and may significantly reduce your available time — or
prevent you from participating altogether. We will not be able to pause the clock for setup
problems.
Rules
1. Individual work only — Do not collaborate with, share information with, or seek help
from other candidates during the assessment.
2. AI tools are allowed — You may use GitHub Copilot, ChatGPT, Claude, or any other
AI assistant. This is a real-world assessment and we want to see how you work with
the tools you normally use.
3. Internet access is allowed — Documentation, Stack Overflow, blog posts, etc. are all
fair game.
4. All work must be in your repo — Commit and push your changes. Unpushed local
changes cannot be evaluated.
5. Transfer repo ownership at the end — When time is up, you will transfer ownership
of your repository to the organizer's GitHub account. Instructions will be provided at the
event. This is mandatory — we cannot evaluate repos that have not been transferred.
6. Do not share the assessment content — The tickets, feature tasks, codebase, and
setup are confidential. Do not share them publicly or with other candidates, during or
after the event.
Investigation Reports
For each ticket or feature task you work on, you may optionally fill out a short investigation
report documenting your process, design decisions, root cause analysis, fix description, and
a response to the reporter. Instructions and a template will be included in the assessment
repository.
These reports are not required — you will not lose points for skipping them. However, wellwritten reports earn extra credit and help us understand your thought process, especially if
you ran out of time to fully fix or implement something. A thorough report on an incomplete
ticket or feature is worth more than a silent attempt with no context.
What We're Looking For
We evaluate your work across multiple dimensions:
• Debugging ability — Can you trace issues from user-reported symptoms through the
backend code, database, and infrastructure to find root causes?
• Fix quality — Do you address the root cause or just the symptom? Do your fixes
prevent recurrence?
• Prioritization — How do you decide which tickets to tackle? Do you manage your time
effectively across 3 hours?
• Engineering practices — Clean code, meaningful commit messages, atomic commits,
test coverage where appropriate
• Communication — Your commit messages, investigation reports, and any notes you
leave are how we understand your thought process
What NOT to Worry About
• You don't need to complete everything. We'd rather see a handful of wellinvestigated fixes or solid feature implementations than many shallow attempts.
• The frontend is not your problem. It's pre-built and read-only. Use your browser
DevTools to see what API calls it makes, but you won't need to change any frontend
code.
• There are no trick questions. Every ticket describes a real, reproducible bug. If
something seems broken, it probably is.
• Perfection is not expected. We're evaluating how you think and work, not whether
you know every PHP function by heart.
Good luck!
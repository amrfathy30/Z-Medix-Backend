# Z-Medix Business Context

> This file contains only confirmed business rules. It is the primary business reference for backend development and AI coding agents.
> Do not invent or assume any business rule that is not explicitly documented here.

## 1. Product Scope

- Z-Medix is an educational platform for medical students.
- There is no university, faculty, or academic-year hierarchy in the current scope.
- The product is English-only.
- The same backend serves both Web and Mobile applications.

## 2. Student Registration

A student registers with:

- Full name
- Email
- Phone
- Country
- Password
- Password confirmation

## 3. Main Learning Flows

The platform has two separate learning flows:

1. Subject-based learning.
2. Fellowship question-bank practice.

They use the same core quiz/attempt behavior, but the source of questions and statistics context are different.

## 4. Subject Structure

A Subject contains two independent content types:

- Chapters
- Books

Both Chapters and Books belong directly to a Subject.

A Book is not a parent of a Chapter, and a Chapter is not a parent of a Book.

```text
Subject
|-- Chapters
`-- Books
```

## 5. Chapters

- Chapters have an explicit order inside a Subject.
- A Chapter contains study content authored by the platform owner/admin through a rich text editor.
- The editor may contain text, images, videos, and other content elements supported by the selected editor.
- Text highlighting applies only to selectable text content.
- Images, videos, and other non-text elements are not highlight targets.
- Each Chapter has a fixed Chapter Quiz.
- The student must complete all questions in the current Chapter Quiz before the next Chapter becomes available.
- There is no minimum passing percentage required to unlock the next Chapter.
- Chapter completion means answering/completing all questions in that Chapter Quiz.
- If the student leaves an unfinished Chapter Quiz, the same attempt is resumed from the same point later.

## 6. Books

- Books belong directly to a Subject.
- Books are independent from Chapters.
- A Book can be a PDF or page-based content authored through the rich text editor.
- Page-based Book content can support text highlighting.
- PDF Books do not support text highlighting in the current scope.

## 7. Highlights and Notes

- A student can create Highlights on supported selectable text content.
- A student can create Notes independently from Highlights.
- A Note does not require an associated Highlight.
- The student has a dedicated area that collects the student's Highlights and Notes.

## 8. Question Rules

All currently confirmed quiz questions are:

- Multiple-choice questions.
- Single-answer questions.
- Exactly one option is correct.

### Correct-answer protection

Before answer submission:

- The backend must not expose the correct answer to the client.
- The correct answer must not be included in any frontend-inspectable response before submission.

After answer submission:

- The student is told whether the submitted answer is correct or incorrect.
- The backend can return the correct answer.
- No answer explanation is required.

## 9. Shared Quiz Engine

Subject Quizzes and Fellowship Sessions use the same core quiz/attempt engine.

The common engine supports:

- Questions and options.
- Student answers.
- Correct/incorrect state.
- Current question position.
- Completed question progress.
- Overall score.
- Overall percentage.
- Correct count.
- Incorrect count.
- Resume of an unfinished attempt.

The quiz engine must not be implemented twice.

### Subject Quiz source

```text
Chapter
-> Fixed Chapter Questions
-> Quiz Attempt
-> Result
-> Subject/Chapter Statistics
```

### Fellowship Session source

```text
Fellowship Question Bank
-> Session Filters
-> Selected Questions
-> Quiz Attempt
-> Result
-> Fellowship Statistics
```

The result mechanics are shared, while statistics can differ by context.

## 10. Chapter Quiz

- Each Chapter has its own fixed set of questions.
- The platform owner/admin defines those questions.
- The same Chapter Quiz questions are used for all students.
- Questions are not dynamically generated per student.
- The student must complete all questions to complete the Chapter.
- The result contains an overall score/percentage and per-question correct/incorrect state.
- An unfinished attempt resumes from the same point.

## 11. Fellowship Question Bank

- Fellowship is a separate question-bank practice flow.
- Fellowship questions are managed by the platform owner/admin.
- Fellowship questions are imported through Excel.
- The platform defines the required core import columns and validation rules.
- Classification/filter data is dynamic and comes from the Excel file.

## 12. Dynamic Fellowship Session Topics

The Fellowship session topics are data-driven from the uploaded Excel structure.

For classification columns in the approved import file:

- The column header becomes a Set Session Topic group.
- The distinct values under that header become selectable values inside that topic group.
- Topic groups are not hard-coded as individual database columns.
- New approved classification headers can create new topic groups without a database migration for every new filter type.

Example:

```text
Excel header: Systems
Values:
- Cardiovascular
- Respiratory
- Neurology

Becomes:
Set Session Topic: Systems
Options:
- Cardiovascular
- Respiratory
- Neurology
```

The same rule applies to other classification headers supplied in the approved question import format.

## 13. Fellowship Session Builder

The student can create a Fellowship practice session using:

- Dynamic Set Session Topics from the imported question data.
- Question Status based on whether the current student answered the question before.
- Question Difficulty.
- Whether questions have a configured time limit.
- Questions Count.
- A custom Session Title.

### Difficulty values

The approved difficulty values are:

- Easy
- Medium
- Hard

Difficulty is required for Fellowship questions.

### Question time

- The administrator can configure a time limit per question.
- If the configured time expires before the student submits an answer, that question is considered unanswered.

### Question count validation

If the requested count is greater than the number of questions matching the selected criteria:

- The session must not start.
- The backend returns a validation error explaining that the available matching questions are insufficient.

If more questions match than requested:

- The system takes the first requested number according to a deterministic system order.
- Selection is not random.

## 14. Fellowship Session Resume

- If the student leaves an unfinished Fellowship Session, the same session remains in progress.
- The student resumes the same session from the same point later.

## 15. Results and Statistics

Subject Quizzes and Fellowship Sessions use the same result mechanics:

- Overall score.
- Overall percentage.
- Correct count.
- Incorrect count.
- Per-question correct/incorrect state.
- The system keeps all previous Chapter Quiz attempts and Fellowship Session attempts for the student.
- The student can return to previous attempts and review their saved results/history.

Statistics are context-specific:

- Subject statistics are based on Subject/Chapter learning and quiz activity.
- Fellowship statistics are based on Fellowship question-bank practice activity.

## 16. AI Assistant

- The AI Assistant must answer only from Z-Medix data/content and content explicitly selected or made available to it.
- The AI Assistant must not use general external knowledge as the answer source.
- The student selects the study context/source used by the AI.

Supported context sources include:

- A Chapter.
- A Book.
- A platform-owned file available to the student.
- A file previously uploaded by the student to the student's library.

The AI context follows the source selected by the student.

## 17. Student File Library

- A student can upload personal files to the student's library.
- Supported file types currently include PDF and Word documents.
- Student files are persistent library files, not temporary chat-only uploads.
- The student can later select an existing library file and use it as AI context.
- File-size and file-count limits are backend validation rules.

## 18. Student Administration

The administrator can:

- View students.
- Edit students.
- Block students.
- View student quiz activity.
- View student progress.
- View student AI usage.

## 19. Notifications

The currently confirmed notification events are:

- Subscription activation.
- Subscription expiration.

The Subscription business model itself is not yet defined in this core document.

## 20. Backend Responsibility

The backend is the authority for:

- Authentication and authorization.
- Content and question access.
- Correct-answer protection.
- Quiz/session state.
- Attempt progress and resume.
- Scores and percentages.
- Fellowship filter validation.
- Student question history used by Question Status filtering.
- Student file ownership and access.
- AI context access control.

Security-sensitive rules must never depend only on frontend state.

## 21. Implementation Rules for AI Agents

- Read this file before proposing or implementing business logic.
- Do not invent missing business rules.
- Do not hard-code Fellowship topic types as database columns.
- Do not expose correct answers before answer submission.
- Reuse one quiz engine for Subject and Fellowship attempts.
- Persist unfinished attempt/session progress on the server.
- Keep Subscription logic isolated until its business rules are approved.
- If a task requires an undefined business rule, flag it instead of guessing.

## 22. Future Appendices

This file represents the confirmed core business rules.

Additional appendices will be added after the remaining business decisions are clarified and approved. Those appendices may extend this document, but unconfirmed behavior must not be treated as implementation truth before approval.

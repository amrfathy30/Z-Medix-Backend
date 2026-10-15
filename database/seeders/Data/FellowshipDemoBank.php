<?php

namespace Database\Seeders\Data;

use Database\Seeders\FellowshipSeeder;

/**
 * The Fellowships, filters and questions {@see FellowshipSeeder}
 * loads for local development.
 *
 * Written so a screen has something realistic to show: several Fellowships with
 * different sizes, a Performance Topic filter plus two dynamic filters, a mix of
 * difficulties, a few questions that carry no Year (a filter introduced after
 * the question was imported), and one inactive Fellowship that must stay hidden
 * from students.
 *
 * These are plausible exam-style MCQs for exercising the API and the screens.
 * They have not been clinically reviewed and must not be served to real
 * students.
 *
 * @phpstan-type Option string|array{0: string, 1: string}
 * @phpstan-type Item array{topic: string, difficulty: string, title: string|null, filters: array<string, string>, question: string, options: list<Option>, explanation: string}
 */
final class FellowshipDemoBank
{
    public const EXAM_FILTER = 'Exam';

    public const YEAR_FILTER = 'Year';

    public const PERFORMANCE_FILTER = 'Performance Topic';

    /**
     * @return list<array{name: string, is_active: bool, items: list<Item>}>
     */
    public static function fellowships(): array
    {
        return [
            [
                'name' => 'Internal Medicine Board',
                'is_active' => true,
                'items' => [
                    ...self::cardiology(),
                    ...self::neurology(),
                    ...self::endocrinology(),
                    ...self::gastroenterology(),
                    ...self::respiratory(),
                    ...self::nephrology(),
                ],
            ],
            [
                'name' => 'General Surgery Board',
                'is_active' => true,
                'items' => [
                    ...self::trauma(),
                    ...self::acuteAbdomen(),
                    ...self::hepatobiliary(),
                ],
            ],
            [
                'name' => 'Pediatrics Board',
                'is_active' => true,
                'items' => [
                    ...self::neonatology(),
                    ...self::pediatricInfections(),
                ],
            ],
            [
                'name' => 'Obstetrics & Gynecology Board',
                'is_active' => false,
                'items' => self::obstetricsAndGynecology(),
            ],
        ];
    }

    /**
     * Cardiology carries a title and an explanation on every option, so the
     * fully-populated shape of a question is covered; the other topics carry
     * only the question-level explanation, which is what most imports produce.
     *
     * @return list<Item>
     */
    private static function cardiology(): array
    {
        $topic = 'Cardiology';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2023',
                'A 58-year-old man presents with central crushing chest pain radiating to the left arm for 40 minutes. ECG shows ST-segment elevation in leads II, III and aVF. Which coronary artery is most likely occluded?',
                ['Right coronary artery', 'The usual supply of the inferior wall, including the SA and AV nodes in most people.'],
                [
                    ['Left anterior descending artery', 'Supplies the anterior wall and septum; occlusion gives ST elevation in V1–V4.'],
                    ['Left circumflex artery', 'Supplies the lateral wall; changes appear in I, aVL, V5–V6.'],
                    ['Left main stem', 'Causes extensive anterior and lateral changes, usually with haemodynamic collapse.'],
                ],
                'ST elevation in II, III and aVF is an inferior STEMI, most commonly due to right coronary artery occlusion. Check right-sided leads for right ventricular involvement before giving nitrates.',
                title: 'Inferior STEMI culprit vessel',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2024',
                'A 70-year-old woman with palpitations has an irregularly irregular pulse and an ECG showing absent P waves. Her CHA2DS2-VASc score is 4. What is the most appropriate long-term management to reduce her stroke risk?',
                ['Oral anticoagulation with a direct oral anticoagulant', 'Reduces thromboembolic stroke risk in non-valvular AF and is preferred over warfarin.'],
                [
                    ['Aspirin 75 mg daily', 'Antiplatelets are not recommended for stroke prevention in AF.'],
                    ['Clopidogrel alone', 'Offers little protection compared with anticoagulation.'],
                    ['Rate control with bisoprolol only', 'Controls symptoms and ventricular rate but does not reduce stroke risk.'],
                ],
                'Anticoagulation is offered in AF when the CHA2DS2-VASc score is 2 or more. A DOAC is the usual first choice in non-valvular AF; rate or rhythm control addresses symptoms, not stroke risk.',
                title: 'Stroke prevention in atrial fibrillation',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2022',
                'A 55-year-old man has exertional chest pain that is relieved by rest. Which drug is most appropriate for immediate relief of an episode of angina?',
                ['Sublingual glyceryl trinitrate', 'A short-acting venodilator that relieves an attack within minutes.'],
                [
                    ['Oral aspirin 300 mg', 'Used in acute coronary syndromes, not to relieve stable angina.'],
                    ['Oral bisoprolol', 'Prevents attacks over time; too slow to relieve one.'],
                    ['Intravenous amiodarone', 'An antiarrhythmic with no role in relieving angina.'],
                ],
                'Short-acting GTN is for immediate symptom relief. Beta-blockers or calcium-channel blockers are the first-line drugs to prevent attacks.',
                title: 'Relief of an angina attack',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2025',
                'A 25-year-old man collapses while playing football. Echocardiography shows asymmetric septal hypertrophy with systolic anterior motion of the mitral valve. What is the most likely inheritance pattern of the underlying condition?',
                ['Autosomal dominant', 'Hypertrophic cardiomyopathy is usually caused by sarcomeric gene mutations inherited this way.'],
                [
                    ['Autosomal recessive', 'Not the pattern for the common sarcomeric mutations.'],
                    ['X-linked recessive', 'Seen in Duchenne-related cardiomyopathy, not in HCM.'],
                    ['Mitochondrial', 'Causes some cardiomyopathies, but not hypertrophic cardiomyopathy of this kind.'],
                ],
                'This is hypertrophic cardiomyopathy, most often due to beta-myosin heavy chain or myosin-binding protein C mutations, inherited in an autosomal dominant pattern.',
                title: 'Inheritance of hypertrophic cardiomyopathy',
            ),
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2022',
                'Which of the following is a typical examination finding in aortic stenosis?',
                ['Ejection systolic murmur radiating to the carotids', 'The classic murmur of aortic stenosis, with a slow-rising pulse.'],
                [
                    ['Pansystolic murmur at the apex radiating to the axilla', 'Describes mitral regurgitation.'],
                    ['Early diastolic murmur at the left sternal edge', 'Describes aortic regurgitation.'],
                    ['Mid-diastolic murmur with an opening snap at the apex', 'Describes mitral stenosis.'],
                ],
                'Aortic stenosis gives a crescendo–decrescendo ejection systolic murmur radiating to the carotids, a slow-rising pulse and a narrow pulse pressure.',
                title: 'Murmur of aortic stenosis',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function neurology(): array
    {
        $topic = 'Neurology';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2023',
                'A 68-year-old man develops sudden right-sided weakness and expressive dysphasia. Which vascular territory is most likely affected?',
                'Left middle cerebral artery',
                ['Right middle cerebral artery', 'Left posterior cerebral artery', 'Basilar artery'],
                'Right-sided weakness with a non-fluent aphasia localises to the dominant (left) hemisphere, in the middle cerebral artery territory.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2024',
                'A 30-year-old woman with a previous episode of optic neuritis now has relapsing and remitting weakness and sensory loss. MRI shows periventricular white-matter lesions. What is the most likely diagnosis?',
                'Multiple sclerosis',
                ['Acute disseminated encephalomyelitis', 'Guillain–Barré syndrome', 'Hypertensive encephalopathy'],
                'Episodes separated in time and place, with optic neuritis and periventricular lesions, indicate relapsing-remitting multiple sclerosis. ADEM is monophasic and usually follows an infection.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', null,
                'A 25-year-old woman has episodes of staring and lip-smacking lasting one to two minutes, preceded by a rising epigastric sensation. What type of seizure is this?',
                'Focal impaired-awareness seizure of temporal lobe origin',
                ['Absence seizure', 'Generalised tonic–clonic seizure', 'Myoclonic seizure'],
                'A rising epigastric aura followed by automatisms such as lip-smacking is typical of a temporal lobe focal seizure. Absence seizures last seconds and have no aura.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2025',
                'A 40-year-old woman has fatigable ptosis and diplopia that worsen towards the end of the day. Which antibody is most likely to be found?',
                'Anti-acetylcholine receptor antibodies',
                ['Anti-voltage-gated calcium channel antibodies', 'Anti-GQ1b antibodies', 'Anti-NMDA receptor antibodies'],
                'This is myasthenia gravis, with acetylcholine receptor antibodies in most generalised cases. Calcium-channel antibodies point to Lambert–Eaton syndrome and anti-GQ1b to Miller Fisher syndrome.',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2024',
                'Cerebrospinal fluid from a patient with Guillain–Barré syndrome most characteristically shows which pattern?',
                'Raised protein with a normal cell count',
                ['Raised neutrophils with low glucose', 'Raised lymphocytes with normal protein', 'Xanthochromia'],
                'Albuminocytological dissociation — high protein without a rise in cells — is the classic CSF finding in Guillain–Barré syndrome, though it may be absent in the first week.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function endocrinology(): array
    {
        $topic = 'Endocrinology';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2022',
                'Which drug is the first-line pharmacological treatment for a newly diagnosed overweight patient with type 2 diabetes and normal renal function?',
                'Metformin',
                ['Gliclazide', 'Insulin glargine', 'Pioglitazone'],
                'Metformin is first line: it does not cause weight gain or hypoglycaemia and has cardiovascular benefit. It is avoided when eGFR is markedly reduced.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2023',
                'A 22-year-old man with type 1 diabetes presents with vomiting, abdominal pain and deep sighing respiration. Glucose is 28 mmol/L, pH 7.1 and ketones 5.0 mmol/L. What is the most important initial step in management?',
                'Intravenous 0.9% sodium chloride fluid resuscitation',
                ['A subcutaneous bolus of insulin glargine', 'Intravenous sodium bicarbonate', 'Oral rehydration with basal insulin'],
                'In diabetic ketoacidosis, fluid resuscitation comes first, followed by a fixed-rate intravenous insulin infusion and potassium replacement guided by serum levels. Bicarbonate is not routinely given.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2024',
                'A 35-year-old woman has weight loss, heat intolerance, a diffuse goitre, exophthalmos and pretibial myxoedema. What is the most likely diagnosis?',
                "Graves' disease",
                ['Toxic multinodular goitre', "Hashimoto's thyroiditis", "Subacute (de Quervain's) thyroiditis"],
                "Exophthalmos and pretibial myxoedema occur only in Graves' disease, driven by TSH-receptor stimulating antibodies.",
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2025',
                'A 45-year-old woman has hypertension and hypokalaemia. Plasma renin is suppressed and aldosterone is raised. What is the most likely diagnosis?',
                "Primary hyperaldosteronism (Conn's syndrome)",
                ['Renal artery stenosis', "Cushing's syndrome", 'Phaeochromocytoma'],
                'Hypertension with hypokalaemia, high aldosterone and low renin is primary hyperaldosteronism. In renal artery stenosis both renin and aldosterone are raised.',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', null,
                "Which combination of findings best supports primary adrenal insufficiency (Addison's disease)?",
                'High ACTH, low cortisol, hyponatraemia and hyperkalaemia',
                [
                    'Low ACTH, low cortisol, hyponatraemia and normal potassium',
                    'High ACTH, high cortisol and hypokalaemia',
                    'Low ACTH, high cortisol and hypokalaemia',
                ],
                'In primary adrenal failure the pituitary responds with high ACTH, and loss of mineralocorticoid causes hyponatraemia and hyperkalaemia. Secondary insufficiency has low ACTH and spares potassium.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function gastroenterology(): array
    {
        $topic = 'Gastroenterology';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2022',
                'Which organism is most commonly associated with duodenal ulcer disease?',
                'Helicobacter pylori',
                ['Clostridioides difficile', 'Campylobacter jejuni', 'Salmonella typhi'],
                'Helicobacter pylori is found in the large majority of duodenal ulcers; eradication therapy heals the ulcer and prevents recurrence.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2023',
                'A 28-year-old woman has chronic diarrhoea, weight loss and iron-deficiency anaemia. Anti-tissue transglutaminase antibodies are strongly positive. What is the most appropriate step to confirm the diagnosis?',
                'Duodenal biopsy while she is still eating gluten',
                [
                    'Start a gluten-free diet and review in three months',
                    'Colonoscopy with terminal ileal biopsy',
                    'Hydrogen breath test',
                ],
                'Coeliac disease is confirmed by duodenal biopsy showing villous atrophy, crypt hyperplasia and intra-epithelial lymphocytes. Gluten must not be withdrawn first or the biopsy may be falsely normal.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2024',
                'A 50-year-old man with long-standing alcohol excess presents with haematemesis. Endoscopy shows bleeding oesophageal varices. Which drug should be started in addition to endoscopic band ligation?',
                'Intravenous terlipressin',
                ['Oral propranolol', 'Intravenous furosemide', 'Oral spironolactone'],
                'Acute variceal bleeding is managed with resuscitation, a vasoactive drug such as terlipressin, antibiotic prophylaxis and band ligation. Non-selective beta-blockers are for secondary prevention afterwards.',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2025',
                "Which feature is characteristic of Crohn's disease rather than ulcerative colitis?",
                'Transmural inflammation with skip lesions and non-caseating granulomas',
                [
                    'Continuous inflammation beginning at the rectum',
                    'Disease confined to the colon and mucosa',
                    'Crypt abscesses with goblet-cell depletion',
                ],
                "Crohn's disease can affect any part of the gut, is transmural and patchy, and may show granulomas. Ulcerative colitis is continuous from the rectum and limited to the mucosa.",
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2024',
                'A 60-year-old man with cirrhosis and ascites has a diagnostic paracentesis. The ascitic neutrophil count is 400 cells/mm³. What is the most appropriate treatment?',
                'Intravenous broad-spectrum antibiotic with albumin',
                [
                    'Large-volume paracentesis alone',
                    'Increase the dose of oral spironolactone',
                    'Intravenous terlipressin alone',
                ],
                'An ascitic neutrophil count above 250 cells/mm³ diagnoses spontaneous bacterial peritonitis. Treat with an intravenous third-generation cephalosporin and albumin to protect renal function.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function respiratory(): array
    {
        $topic = 'Respiratory';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2022',
                'Which organism is the most common cause of community-acquired pneumonia?',
                'Streptococcus pneumoniae',
                ['Haemophilus influenzae', 'Legionella pneumophila', 'Staphylococcus aureus'],
                'Streptococcus pneumoniae causes the largest share of community-acquired pneumonia in adults.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2023',
                'A 65-year-old smoker with COPD is increasingly breathless. Arterial blood gas on room air shows pH 7.28, PaCO2 8.5 kPa, PaO2 7.2 kPa and bicarbonate 34 mmol/L. How is this best interpreted?',
                'Acute-on-chronic type 2 respiratory failure with respiratory acidosis',
                [
                    'Metabolic acidosis with respiratory compensation',
                    'Type 1 respiratory failure',
                    'Respiratory alkalosis',
                ],
                'A low pH with a raised PaCO2 is respiratory acidosis. The raised bicarbonate shows chronic retention, and the acidaemia shows an acute worsening on top of it.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2024',
                'A patient has tension pneumothorax with haemodynamic compromise. What is the most appropriate immediate management?',
                'Immediate needle decompression with a large-bore cannula',
                [
                    'Obtain a chest X-ray to confirm the diagnosis',
                    'Intravenous fluids and observation',
                    'Urgent CT pulmonary angiogram',
                ],
                'Tension pneumothorax is a clinical diagnosis. Decompress at once, then insert a chest drain; imaging first delays treatment and can be fatal.',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2025',
                'A patient has an FEV1/FVC ratio of 55%, an increased total lung capacity and a reduced gas transfer factor (TLCO). What is the most likely diagnosis?',
                'Emphysema',
                ['Chronic asthma', 'Idiopathic pulmonary fibrosis', 'Neuromuscular weakness'],
                'An obstructive spirometry pattern with air trapping and a low TLCO indicates emphysema. Asthma usually has a normal or raised TLCO, and fibrosis is restrictive.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', null,
                'A 45-year-old woman has a dry cough, erythema nodosum and bilateral hilar lymphadenopathy. Which biopsy finding is characteristic of the likely diagnosis?',
                'Non-caseating granulomas',
                ['Caseating granulomas with Langhans giant cells', 'Asbestos bodies', 'Reed–Sternberg cells'],
                'Bilateral hilar lymphadenopathy with erythema nodosum suggests sarcoidosis, which shows non-caseating epithelioid granulomas. Caseation points towards tuberculosis.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function nephrology(): array
    {
        $topic = 'Nephrology';

        return [
            self::item(
                $topic, 'easy', 'MRCP Part 1', '2022',
                'Which electrolyte abnormality is the most immediately life-threatening in acute kidney injury?',
                'Hyperkalaemia',
                ['Hyponatraemia', 'Hypocalcaemia', 'Hypermagnesaemia'],
                'Hyperkalaemia can cause fatal arrhythmias, so it is the first complication to look for and treat in acute kidney injury.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', '2023',
                'A patient has a potassium of 7.1 mmol/L, peaked T waves and a widened QRS complex. Which treatment should be given first?',
                'Intravenous calcium gluconate',
                [
                    'Intravenous insulin with dextrose',
                    'Nebulised salbutamol',
                    'Oral calcium resonium',
                ],
                'Calcium stabilises the cardiac membrane and is given first when there are ECG changes. It does not lower potassium, so insulin–dextrose and salbutamol follow.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2024',
                'An 8-year-old boy has periorbital oedema, cola-coloured urine and hypertension two weeks after a sore throat. Which result is most likely?',
                'Low serum C3 with a raised ASO titre',
                [
                    'Raised serum C3',
                    'Positive anti-GBM antibodies',
                    'Proteinuria above 3.5 g/day with severe hypoalbuminaemia',
                ],
                'This is post-streptococcal glomerulonephritis, a nephritic syndrome with complement consumption. Heavy proteinuria and hypoalbuminaemia indicate a nephrotic picture.',
            ),
            self::item(
                $topic, 'hard', 'MRCP Part 2', '2025',
                'A 30-year-old man has episodes of macroscopic haematuria one to two days after upper respiratory tract infections. What is the most likely renal biopsy finding?',
                'Mesangial IgA deposition',
                [
                    'Subepithelial humps on electron microscopy',
                    'Linear IgG deposition along the basement membrane',
                    'Foot-process effacement with normal light microscopy',
                ],
                'Haematuria occurring at the same time as an infection (synpharyngitic) is typical of IgA nephropathy, which shows mesangial IgA deposits.',
            ),
            self::item(
                $topic, 'medium', 'MRCP Part 2', null,
                'Which finding is consistent with prerenal acute kidney injury rather than acute tubular necrosis?',
                'Urine sodium below 20 mmol/L with urine osmolality above 500 mOsm/kg',
                [
                    'Muddy-brown granular casts on urine microscopy',
                    'Fractional excretion of sodium above 2%',
                    'Urine osmolality close to plasma osmolality',
                ],
                'In prerenal failure the tubules still work, so sodium is retained and the urine is concentrated. Granular casts, a high FeNa and isosthenuria point to tubular necrosis.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function trauma(): array
    {
        $topic = 'Trauma';

        return [
            self::item(
                $topic, 'easy', 'MRCS Part A', '2023',
                'A 30-year-old is hypotensive and tachycardic after a road traffic collision. He has distended neck veins, absent breath sounds on the right and a hyper-resonant right chest. What is the most likely diagnosis?',
                'Tension pneumothorax',
                ['Cardiac tamponade', 'Massive haemothorax', 'Flail chest'],
                'Hyper-resonance with absent breath sounds and distended neck veins is tension pneumothorax. A massive haemothorax is dull to percussion and the neck veins are flat.',
            ),
            self::item(
                $topic, 'medium', 'MRCS Part A', '2024',
                'Which class of haemorrhage is characterised by a 30–40% loss of blood volume, a heart rate above 120, a lowered blood pressure and confusion?',
                'Class III',
                ['Class I', 'Class II', 'Class IV'],
                'ATLS classes: I is under 15% loss, II is 15–30%, III is 30–40% and IV is over 40%. Hypotension appears from class III.',
            ),
            self::item(
                $topic, 'medium', 'FRCS', '2022',
                'A patient is hypotensive after blunt abdominal trauma. A FAST scan shows free fluid in Morison’s pouch and he does not respond to fluid resuscitation. What is the most appropriate next step?',
                'Emergency laparotomy',
                ['CT of the abdomen with contrast', 'Diagnostic peritoneal lavage', 'Observation with serial examinations'],
                'A haemodynamically unstable patient with a positive FAST needs a laparotomy. CT is only for patients who are stable enough to travel.',
            ),
            self::item(
                $topic, 'hard', 'FRCS', null,
                'A patient with a penetrating precordial wound has hypotension, a raised JVP and muffled heart sounds. What is the definitive treatment?',
                'Surgical exploration and repair of the cardiac wound',
                [
                    'Repeated pericardiocentesis',
                    'Needle decompression of the second intercostal space',
                    'Intravenous inotropes alone',
                ],
                'This is cardiac tamponade after penetrating trauma. Pericardiocentesis can buy time, but the definitive treatment is thoracotomy or sternotomy to repair the injury.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2023',
                'What is the most appropriate immediate management of an open (sucking) chest wound?',
                'An occlusive dressing taped on three sides',
                [
                    'A dressing sealed on all four sides',
                    'Insert a chest drain through the wound',
                    'Probe the wound to estimate its depth',
                ],
                'A three-sided dressing acts as a flutter valve: air escapes on expiration, but cannot be drawn in on inspiration, which would convert the wound into a tension pneumothorax.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function acuteAbdomen(): array
    {
        $topic = 'Acute Abdomen';

        return [
            self::item(
                $topic, 'easy', 'MRCS Part A', '2022',
                'A 22-year-old has periumbilical pain that has migrated to the right iliac fossa, with anorexia and a low-grade fever. What is the most likely diagnosis?',
                'Acute appendicitis',
                ['Mesenteric adenitis', 'Ureteric colic', "Meckel's diverticulitis"],
                'Visceral periumbilical pain that localises to the right iliac fossa once the parietal peritoneum is irritated is the classic history of appendicitis.',
            ),
            self::item(
                $topic, 'medium', 'MRCS Part A', '2023',
                'A 70-year-old man with atrial fibrillation has severe abdominal pain that is out of proportion to the examination findings. What is the most likely diagnosis?',
                'Acute mesenteric ischaemia',
                ['Perforated peptic ulcer', 'Acute pancreatitis', 'Diverticulitis'],
                'Pain out of proportion to signs in a patient with an embolic source such as atrial fibrillation suggests mesenteric ischaemia, a surgical emergency.',
            ),
            self::item(
                $topic, 'medium', 'FRCS', '2024',
                'A patient has sudden severe epigastric pain and a board-like abdomen. What would an erect chest radiograph most likely show?',
                'Free air under the diaphragm',
                [
                    'Multiple small-bowel air–fluid levels',
                    'A dilated sigmoid colon with a coffee-bean sign',
                    'Thumbprinting of the transverse colon',
                ],
                'Pneumoperitoneum under the diaphragm on an erect film indicates a perforated viscus, most often a peptic ulcer.',
            ),
            self::item(
                $topic, 'hard', 'FRCS', null,
                'What is the most common cause of small-bowel obstruction in adults in developed countries?',
                'Adhesions from previous abdominal surgery',
                ['Incarcerated inguinal hernia', 'Small-bowel tumour', 'Gallstone ileus'],
                'Post-operative adhesions cause most adult small-bowel obstruction in developed countries, followed by hernias and neoplasms.',
            ),
            self::item(
                $topic, 'hard', 'Arab Board', '2024',
                'A 60-year-old woman has colicky abdominal pain, vomiting, distension and absolute constipation. She has a tender, irreducible lump below and lateral to the pubic tubercle. What is the most likely diagnosis?',
                'Strangulated femoral hernia',
                ['Strangulated inguinal hernia', 'Obturator hernia', 'Psoas abscess'],
                'A femoral hernia lies below and lateral to the pubic tubercle, whereas an inguinal hernia lies above and medial to it. It is more common in women and prone to strangulation.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function hepatobiliary(): array
    {
        $topic = 'Hepatobiliary & Pancreas';

        return [
            self::item(
                $topic, 'easy', 'MRCS Part A', '2022',
                'Which investigation is the first-line imaging test for suspected gallstones?',
                'Transabdominal ultrasound',
                ['CT of the abdomen', 'MRCP', 'HIDA scan'],
                'Ultrasound is cheap, non-invasive and sensitive for gallstones and a dilated bile duct.',
            ),
            self::item(
                $topic, 'medium', 'MRCS Part A', '2023',
                'A patient has fever, jaundice and right upper quadrant pain (Charcot’s triad). What is the most likely diagnosis?',
                'Acute ascending cholangitis',
                ['Acute cholecystitis', 'Biliary colic', 'Hepatitis A'],
                'Charcot’s triad indicates infected, obstructed bile ducts. Add hypotension and confusion for Reynolds’ pentad. Treat with antibiotics and early biliary decompression.',
            ),
            self::item(
                $topic, 'medium', 'FRCS', '2024',
                'What is the most common cause of acute pancreatitis in the UK?',
                'Gallstones',
                ['Alcohol', 'Hypertriglyceridaemia', 'Post-ERCP injury'],
                'Gallstones are the most common cause of acute pancreatitis in the UK, followed by alcohol.',
            ),
            self::item(
                $topic, 'hard', 'FRCS', null,
                'A 65-year-old has painless jaundice and weight loss. CT shows a resectable 3 cm mass in the head of the pancreas. What is the most appropriate operation?',
                "Pancreaticoduodenectomy (Whipple's procedure)",
                [
                    'Distal pancreatectomy',
                    'Roux-en-Y hepaticojejunostomy alone',
                    'Total gastrectomy',
                ],
                'A resectable tumour in the pancreatic head is treated with pancreaticoduodenectomy. A bypass alone is palliative, and a distal pancreatectomy is for tumours of the body or tail.',
            ),
            self::item(
                $topic, 'hard', 'Arab Board', '2023',
                'In laparoscopic cholecystectomy, which structures are divided once the critical view of safety has been achieved?',
                'The cystic duct and the cystic artery',
                [
                    'The common bile duct and the right hepatic artery',
                    'The common hepatic duct and the cystic artery',
                    'The cystic duct and the right hepatic artery',
                ],
                'Only two structures should enter the gallbladder in the critical view: the cystic duct and cystic artery. Dividing anything else risks a bile duct or hepatic artery injury.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function neonatology(): array
    {
        $topic = 'Neonatology';

        return [
            self::item(
                $topic, 'easy', 'MRCPCH', '2022',
                'What is the most common cause of respiratory distress in a baby born at 28 weeks’ gestation?',
                'Respiratory distress syndrome due to surfactant deficiency',
                ['Transient tachypnoea of the newborn', 'Meconium aspiration syndrome', 'Congenital diaphragmatic hernia'],
                'Surfactant is produced late in gestation, so the more preterm the baby the greater the risk of respiratory distress syndrome. Antenatal steroids reduce it.',
            ),
            self::item(
                $topic, 'medium', 'MRCPCH', '2023',
                'A term baby becomes jaundiced within the first 24 hours of life. What is the most likely cause?',
                'Haemolytic disease, such as ABO or rhesus incompatibility',
                ['Physiological jaundice', 'Breast-milk jaundice', 'Biliary atresia'],
                'Jaundice in the first 24 hours is always pathological until proven otherwise, and haemolysis is the main cause. Physiological jaundice appears after 24 hours.',
            ),
            self::item(
                $topic, 'medium', 'Arab Board', '2024',
                'Which drug keeps the ductus arteriosus open in a neonate with duct-dependent congenital heart disease such as transposition of the great arteries?',
                'Prostaglandin E1 (alprostadil)',
                ['Indometacin', 'Ibuprofen', 'Caffeine citrate'],
                'Prostaglandin E1 maintains ductal patency until surgery. Indometacin and ibuprofen close a patent ductus arteriosus.',
            ),
            self::item(
                $topic, 'hard', 'MRCPCH', null,
                'A 3-week-old boy has projectile non-bilious vomiting after every feed, and an olive-shaped mass is palpable in the abdomen. Which metabolic abnormality is expected?',
                'Hypochloraemic hypokalaemic metabolic alkalosis',
                ['Hyperchloraemic metabolic acidosis', 'Hyperkalaemic metabolic acidosis', 'Respiratory alkalosis'],
                'Pyloric stenosis causes loss of gastric acid and chloride, giving a hypochloraemic, hypokalaemic metabolic alkalosis. Correct it before pyloromyotomy.',
            ),
            self::item(
                $topic, 'easy', 'MRCPCH', '2023',
                'Which condition is screened for on the newborn blood-spot (heel-prick) test and treated with dietary restriction of phenylalanine?',
                'Phenylketonuria',
                ['Congenital hypothyroidism', 'Cystic fibrosis', 'Medium-chain acyl-CoA dehydrogenase deficiency'],
                'Phenylketonuria is detected on the blood-spot screen and managed with a phenylalanine-restricted diet from the first weeks of life, which prevents intellectual disability.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function pediatricInfections(): array
    {
        $topic = 'Infectious Diseases';

        return [
            self::item(
                $topic, 'easy', 'MRCPCH', '2022',
                'Koplik spots are a feature of which infection?',
                'Measles',
                ['Rubella', 'Scarlet fever', 'Hand, foot and mouth disease'],
                'Koplik spots are white spots on the buccal mucosa that appear before the measles rash.',
            ),
            self::item(
                $topic, 'medium', 'MRCPCH', '2023',
                'A 2-year-old has a barking cough, inspiratory stridor, a hoarse voice and a low-grade fever. What is the most likely diagnosis?',
                'Croup',
                ['Epiglottitis', 'Bacterial tracheitis', 'Foreign-body aspiration'],
                'A barking cough with stridor and a hoarse voice in a toddler is croup, usually due to parainfluenza virus. A single dose of oral dexamethasone is the treatment.',
            ),
            self::item(
                $topic, 'hard', 'Arab Board', '2024',
                'A 5-year-old has had fever for six days with bilateral non-purulent conjunctivitis, a strawberry tongue, cervical lymphadenopathy and peeling of the fingertips. Which treatment reduces the risk of coronary artery aneurysm?',
                'Intravenous immunoglobulin with aspirin',
                ['Oral penicillin V', 'Oral prednisolone alone', 'Intravenous ceftriaxone'],
                'This is Kawasaki disease. Early IV immunoglobulin plus aspirin lowers the risk of coronary aneurysms, and an echocardiogram is needed.',
            ),
            self::item(
                $topic, 'easy', 'MRCPCH', null,
                'Which organism most commonly causes bronchiolitis in infants?',
                'Respiratory syncytial virus',
                ['Influenza A virus', 'Streptococcus pneumoniae', 'Mycoplasma pneumoniae'],
                'Respiratory syncytial virus causes most bronchiolitis, which peaks in winter in infants under one year.',
            ),
            self::item(
                $topic, 'hard', 'MRCPCH', '2024',
                'A child has fever, a non-blanching petechial rash and signs of shock. Which antibiotic should a primary-care doctor give immediately, before transfer to hospital?',
                'Parenteral benzylpenicillin',
                ['Oral amoxicillin', 'Oral co-amoxiclav', 'Oral cefalexin'],
                'Suspected meningococcal sepsis is treated at once with parenteral benzylpenicillin, in primary care before transfer, without waiting for investigations.',
            ),
        ];
    }

    /**
     * @return list<Item>
     */
    private static function obstetricsAndGynecology(): array
    {
        return [
            self::item(
                'Obstetrics', 'easy', 'Arab Board', '2023',
                'What is the most common cause of primary postpartum haemorrhage?',
                'Uterine atony',
                ['Retained placental tissue', 'Genital tract trauma', 'Coagulopathy'],
                'Failure of the uterus to contract after delivery causes about 70% of primary postpartum haemorrhages.',
            ),
            self::item(
                'Obstetrics', 'medium', 'Arab Board', '2024',
                'A woman at 34 weeks’ gestation has a blood pressure of 165/110 mmHg, proteinuria and a severe headache. Which drug is first line to prevent eclamptic seizures?',
                'Magnesium sulfate',
                ['Phenytoin', 'Diazepam', 'Levetiracetam'],
                'Magnesium sulfate is the drug of choice for preventing and treating eclamptic seizures, alongside antihypertensive treatment.',
            ),
            self::item(
                'Gynecology', 'medium', 'Arab Board', '2022',
                'Which tumour marker is most associated with epithelial ovarian cancer?',
                'CA-125',
                ['CA 19-9', 'Alpha-fetoprotein', 'Beta-hCG'],
                'CA-125 is raised in most epithelial ovarian cancers, but it is not specific, so it is used with ultrasound in a risk-of-malignancy index.',
            ),
        ];
    }

    /**
     * One question. The first option given is the correct one; the seeder
     * decides where it ends up in the shown order.
     *
     * A null year leaves the question without a Year value.
     *
     * @param  Option  $correct
     * @param  list<Option>  $distractors
     * @return Item
     */
    private static function item(
        string $topic,
        string $difficulty,
        string $exam,
        ?string $year,
        string $question,
        string|array $correct,
        array $distractors,
        string $explanation,
        ?string $title = null,
    ): array {
        $filters = [self::EXAM_FILTER => $exam];

        if ($year !== null) {
            $filters[self::YEAR_FILTER] = $year;
        }

        return [
            'topic' => $topic,
            'difficulty' => $difficulty,
            'title' => $title,
            'filters' => $filters,
            'question' => $question,
            'options' => [$correct, ...$distractors],
            'explanation' => $explanation,
        ];
    }
}

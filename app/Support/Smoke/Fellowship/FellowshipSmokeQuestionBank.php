<?php

namespace App\Support\Smoke\Fellowship;

/**
 * The clinical content the Fellowship smoke workbooks are built from.
 *
 * These are test fixtures, not teaching material: they are medically coherent
 * enough that a reviewer reading the generated workbook sees plausible MCQs with
 * one defensibly correct answer, which is what the import flow needs in order to
 * be exercised honestly. They are not clinically validated and must never be
 * served to a real student.
 *
 * Items are composed from a condition matrix rather than hand-written one by
 * one, so the bank is large enough to fill a hundred-row workbook while every
 * question text stays distinct — distinctness is what duplicate detection is
 * judged against, so two items accidentally sharing a stem would make the
 * duplicate counts meaningless.
 *
 * @phpstan-type BankItem array{system: string, text: string, options: list<string>, correct: int, explanation: string, difficulty: string, exam: string, year: string, source: string}
 */
final class FellowshipSmokeQuestionBank
{
    /** Marker carried by every generated question text, so cleanup stays narrow. */
    public const MARKER = 'FELLOWSHIP_SMOKE_';

    /**
     * One clinical scenario per entry: the presentation, the single best answer,
     * and three plausible distractors.
     *
     * @return list<array{system: string, frame: string, correct: string, distractors: list<string>, explanation: string}>
     */
    private static function scenarios(): array
    {
        return [
            // ── Cardiology ────────────────────────────────────────────────────
            ['system' => 'Cardiology', 'frame' => 'A 58-year-old man presents with crushing central chest pain for 40 minutes and ST elevation in leads II, III and aVF.', 'correct' => 'Immediate primary percutaneous coronary intervention', 'distractors' => ['Oral beta-blocker and discharge', 'Exercise tolerance testing', 'Outpatient echocardiography'], 'explanation' => 'Inferior ST-elevation myocardial infarction requires immediate reperfusion; primary PCI is first line where available.'],
            ['system' => 'Cardiology', 'frame' => 'A 72-year-old woman has an irregularly irregular pulse at 130 bpm, is haemodynamically stable, and has had palpitations for three weeks.', 'correct' => 'Rate control with a beta-blocker and anticoagulation assessment', 'distractors' => ['Immediate DC cardioversion', 'Intravenous adenosine', 'Permanent pacemaker insertion'], 'explanation' => 'Atrial fibrillation of more than 48 hours is rate-controlled first, with stroke risk scored before any rhythm strategy.'],
            ['system' => 'Cardiology', 'frame' => 'A 65-year-old man with known heart failure has worsening orthopnoea, bibasal crepitations and a raised JVP.', 'correct' => 'Intravenous loop diuretic', 'distractors' => ['Intravenous fluid bolus', 'Oral beta-blocker uptitration today', 'Immediate pericardiocentesis'], 'explanation' => 'Decompensated heart failure with congestion is treated with an intravenous loop diuretic; fluid loading would worsen it.'],
            ['system' => 'Cardiology', 'frame' => 'A 48-year-old man collapses and is in pulseless ventricular fibrillation on the monitor.', 'correct' => 'Immediate unsynchronised defibrillation', 'distractors' => ['Synchronised cardioversion', 'Intravenous amiodarone before any shock', 'Carotid sinus massage'], 'explanation' => 'Ventricular fibrillation is a shockable arrest rhythm requiring immediate unsynchronised defibrillation.'],
            ['system' => 'Cardiology', 'frame' => 'A 30-year-old woman has a narrow-complex regular tachycardia at 180 bpm, stable blood pressure, and no response to vagal manoeuvres.', 'correct' => 'Intravenous adenosine', 'distractors' => ['Intravenous digoxin', 'Immediate defibrillation', 'Oral flecainide and discharge'], 'explanation' => 'Stable supraventricular tachycardia unresponsive to vagal manoeuvres is treated with intravenous adenosine.'],
            ['system' => 'Cardiology', 'frame' => 'A 60-year-old man has an ejection systolic murmur radiating to the carotids, slow-rising pulse and exertional syncope.', 'correct' => 'Transthoracic echocardiography to assess aortic stenosis', 'distractors' => ['Empirical antibiotics for endocarditis', 'Immediate coronary bypass grafting', 'Reassurance and discharge'], 'explanation' => 'The murmur, pulse character and syncope point to aortic stenosis, which echocardiography grades before intervention.'],

            // ── Respiratory ───────────────────────────────────────────────────
            ['system' => 'Respiratory', 'frame' => 'A 24-year-old asthmatic cannot complete sentences, has a peak flow of 30% predicted and a silent chest.', 'correct' => 'High-flow oxygen with nebulised salbutamol and ipratropium', 'distractors' => ['Oral prednisolone alone and discharge', 'Observation without treatment', 'Immediate intercostal drain'], 'explanation' => 'A silent chest marks life-threatening asthma; oxygen and nebulised bronchodilators are given immediately.'],
            ['system' => 'Respiratory', 'frame' => 'A 68-year-old smoker has sudden breathlessness, tracheal deviation away from a hyper-resonant hemithorax and hypotension.', 'correct' => 'Immediate needle decompression', 'distractors' => ['Chest radiograph before any intervention', 'Nebulised bronchodilators', 'Intravenous antibiotics'], 'explanation' => 'Tension pneumothorax is a clinical diagnosis treated by immediate decompression, not delayed for imaging.'],
            ['system' => 'Respiratory', 'frame' => 'A 55-year-old woman has pleuritic chest pain and breathlessness ten days after a hip replacement, with a normal chest radiograph.', 'correct' => 'CT pulmonary angiography with therapeutic anticoagulation', 'distractors' => ['Oral antibiotics for pneumonia', 'Discharge with simple analgesia', 'Bronchoscopy'], 'explanation' => 'Recent surgery with pleuritic pain and a clear film makes pulmonary embolism likely; imaging and anticoagulation follow.'],
            ['system' => 'Respiratory', 'frame' => 'A 70-year-old man with COPD has a pH of 7.26, a PaCO2 of 8.5 kPa and drowsiness despite maximal medical therapy.', 'correct' => 'Non-invasive ventilation', 'distractors' => ['Increase inspired oxygen to 15 litres per minute', 'Intravenous doxapram as first line', 'Discharge on oral steroids'], 'explanation' => 'Persisting hypercapnic acidosis after medical therapy is the indication for non-invasive ventilation.'],
            ['system' => 'Respiratory', 'frame' => 'A 42-year-old presents with a productive cough, fever and right basal consolidation, with a CURB-65 score of 1.', 'correct' => 'Oral amoxicillin as an outpatient', 'distractors' => ['Intravenous meropenem in intensive care', 'No antibiotic therapy', 'Immediate thoracotomy'], 'explanation' => 'Low-severity community-acquired pneumonia is managed with oral first-line antibiotics in the community.'],

            // ── Gastroenterology ──────────────────────────────────────────────
            ['system' => 'Gastroenterology', 'frame' => 'A 55-year-old man with known cirrhosis vomits a large volume of fresh blood and is tachycardic at 120 bpm.', 'correct' => 'Resuscitation with terlipressin and urgent endoscopy', 'distractors' => ['Oral proton pump inhibitor and outpatient review', 'Barium swallow', 'Immediate laparotomy'], 'explanation' => 'Variceal haemorrhage needs resuscitation, a vasoactive drug and endoscopy within 24 hours.'],
            ['system' => 'Gastroenterology', 'frame' => 'A 38-year-old woman has epigastric pain radiating to the back, vomiting and an amylase three times the upper limit of normal.', 'correct' => 'Aggressive intravenous fluid resuscitation and analgesia', 'distractors' => ['Immediate pancreatic resection', 'Oral feeding challenge straight away', 'Discharge with antacids'], 'explanation' => 'Acute pancreatitis is managed supportively with fluids and analgesia while the cause is sought.'],
            ['system' => 'Gastroenterology', 'frame' => 'A 26-year-old has six weeks of bloody diarrhoea, and sigmoidoscopy shows continuous superficial inflammation from the rectum.', 'correct' => 'Oral and topical mesalazine', 'distractors' => ['Empirical antitubercular therapy', 'Immediate panproctocolectomy', 'Loperamide alone'], 'explanation' => 'Continuous rectal-onset inflammation is ulcerative colitis; mild to moderate disease starts with mesalazine.'],
            ['system' => 'Gastroenterology', 'frame' => 'A 60-year-old man has painless jaundice, weight loss and a palpable gallbladder.', 'correct' => 'CT of the pancreas to assess for malignancy', 'distractors' => ['Reassurance and repeat bloods in a year', 'Empirical antibiotics for cholangitis', 'Immediate cholecystectomy'], 'explanation' => 'Painless jaundice with a palpable gallbladder suggests pancreatic head malignancy and requires staging imaging.'],
            ['system' => 'Gastroenterology', 'frame' => 'A 45-year-old woman has right upper quadrant pain, fever and jaundice with a dilated common bile duct.', 'correct' => 'Antibiotics and urgent biliary drainage by ERCP', 'distractors' => ['Elective cholecystectomy in six months', 'Oral ursodeoxycholic acid alone', 'Observation without antibiotics'], 'explanation' => "Charcot's triad indicates ascending cholangitis, needing antibiotics and urgent decompression."],

            // ── Neurology ─────────────────────────────────────────────────────
            ['system' => 'Neurology', 'frame' => 'A 67-year-old woman has sudden right-sided weakness and dysphasia starting 90 minutes ago, with a CT excluding haemorrhage.', 'correct' => 'Intravenous thrombolysis', 'distractors' => ['Aspirin alone and ward observation', 'Lumbar puncture', 'Therapeutic anticoagulation with heparin'], 'explanation' => 'Ischaemic stroke within the thrombolysis window and without haemorrhage is thrombolysed.'],
            ['system' => 'Neurology', 'frame' => 'A 34-year-old describes the worst headache of his life with sudden onset and neck stiffness; CT is normal at 14 hours.', 'correct' => 'Lumbar puncture looking for xanthochromia', 'distractors' => ['Discharge with simple analgesia', 'Immediate thrombolysis', 'Oral sumatriptan and review in a month'], 'explanation' => 'A normal CT does not exclude subarachnoid haemorrhage; lumbar puncture for xanthochromia is the next step.'],
            ['system' => 'Neurology', 'frame' => 'A 22-year-old has had a generalised seizure lasting more than five minutes and is still convulsing.', 'correct' => 'Intravenous or buccal benzodiazepine', 'distractors' => ['Oral carbamazepine loading', 'Immediate CT before any treatment', 'Intravenous fluids alone'], 'explanation' => 'A seizure beyond five minutes is status epilepticus and a benzodiazepine is given as first line.'],
            ['system' => 'Neurology', 'frame' => 'A 29-year-old woman has optic neuritis and, two years earlier, had a transient sensory disturbance with lesions on MRI.', 'correct' => 'MRI with contrast to confirm dissemination in time and space', 'distractors' => ['Empirical broad-spectrum antibiotics', 'Discharge without follow-up', 'Immediate plasma exchange'], 'explanation' => 'Separate episodes with characteristic lesions suggest multiple sclerosis; MRI establishes dissemination.'],
            ['system' => 'Neurology', 'frame' => 'A 75-year-old man has a resting tremor, bradykinesia and rigidity that is markedly asymmetrical.', 'correct' => 'Levodopa with a dopa-decarboxylase inhibitor', 'distractors' => ['Oral propranolol as definitive therapy', 'Immediate deep brain stimulation', 'Haloperidol'], 'explanation' => "Asymmetrical bradykinetic rigid syndrome is idiopathic Parkinson's disease, treated with levodopa."],

            // ── Endocrinology ─────────────────────────────────────────────────
            ['system' => 'Endocrinology', 'frame' => 'A 19-year-old with type 1 diabetes has a glucose of 28 mmol/L, ketones of 5 mmol/L and a pH of 7.18.', 'correct' => 'Intravenous fluids with a fixed-rate insulin infusion', 'distractors' => ['Subcutaneous long-acting insulin alone', 'Oral metformin', 'Intravenous sodium bicarbonate as first line'], 'explanation' => 'Diabetic ketoacidosis is treated with fluid resuscitation and a fixed-rate intravenous insulin infusion.'],
            ['system' => 'Endocrinology', 'frame' => 'A 44-year-old woman has weight loss, heat intolerance, a fine tremor and a suppressed TSH with a raised free T4.', 'correct' => 'Carbimazole with a beta-blocker for symptom control', 'distractors' => ['Levothyroxine replacement', 'Observation for two years', 'Immediate total thyroidectomy'], 'explanation' => 'Biochemical hyperthyroidism is treated with an antithyroid drug, with a beta-blocker for adrenergic symptoms.'],
            ['system' => 'Endocrinology', 'frame' => 'A 52-year-old man on long-term steroids becomes hypotensive and hyponatraemic after abruptly stopping them.', 'correct' => 'Intravenous hydrocortisone and fluid resuscitation', 'distractors' => ['Fluid restriction', 'Oral fludrocortisone alone next week', 'Intravenous insulin'], 'explanation' => 'Abrupt steroid withdrawal can precipitate an adrenal crisis, treated with intravenous hydrocortisone and fluids.'],
            ['system' => 'Endocrinology', 'frame' => 'A 61-year-old woman has a corrected calcium of 3.2 mmol/L, confusion and a raised parathyroid hormone level.', 'correct' => 'Intravenous saline rehydration followed by assessment for parathyroidectomy', 'distractors' => ['Fluid restriction and oral calcium', 'Immediate haemodialysis in all cases', 'Oral vitamin D loading'], 'explanation' => 'Symptomatic hypercalcaemia is rehydrated first; primary hyperparathyroidism is then assessed surgically.'],

            // ── Nephrology ────────────────────────────────────────────────────
            ['system' => 'Nephrology', 'frame' => 'A 70-year-old man has a potassium of 7.1 mmol/L with peaked T waves on the electrocardiogram.', 'correct' => 'Intravenous calcium gluconate followed by insulin-dextrose', 'distractors' => ['Oral potassium binder alone', 'Observation and repeat in 24 hours', 'Intravenous potassium replacement'], 'explanation' => 'Hyperkalaemia with electrocardiographic change needs immediate myocardial stabilisation then potassium shifting.'],
            ['system' => 'Nephrology', 'frame' => 'A 58-year-old woman has a creatinine rise after starting an ACE inhibitor, with bilateral renal artery bruits.', 'correct' => 'Stop the ACE inhibitor and image the renal arteries', 'distractors' => ['Double the ACE inhibitor dose', 'Start a potassium-sparing diuretic', 'Begin dialysis immediately'], 'explanation' => 'Bilateral renal artery stenosis makes ACE inhibition hazardous; the drug is stopped and the arteries imaged.'],
            ['system' => 'Nephrology', 'frame' => 'A 26-year-old man has frank haematuria two days after an upper respiratory tract infection and normal complement levels.', 'correct' => 'Assess for IgA nephropathy with urinalysis and blood pressure monitoring', 'distractors' => ['Immediate renal transplantation', 'Empirical antituberculous therapy', 'High-dose intravenous immunoglobulin'], 'explanation' => 'Synpharyngitic haematuria with normal complement suggests IgA nephropathy; initial assessment is non-invasive.'],

            // ── Haematology ───────────────────────────────────────────────────
            ['system' => 'Haematology', 'frame' => 'A 32-year-old woman has a microcytic anaemia with a ferritin of 6 micrograms per litre and heavy menstrual bleeding.', 'correct' => 'Oral iron replacement and treat the source of blood loss', 'distractors' => ['Vitamin B12 injections', 'Immediate bone marrow transplantation', 'Therapeutic venesection'], 'explanation' => 'Iron deficiency anaemia is corrected with iron while the bleeding source is addressed.'],
            ['system' => 'Haematology', 'frame' => 'A 64-year-old man has back pain, renal impairment, hypercalcaemia and a monoclonal paraprotein band.', 'correct' => 'Bone marrow biopsy and skeletal imaging for myeloma', 'distractors' => ['Oral iron and discharge', 'Immediate splenectomy', 'Therapeutic phlebotomy'], 'explanation' => 'The CRAB features with a paraprotein indicate myeloma, confirmed by marrow and imaging.'],
            ['system' => 'Haematology', 'frame' => 'A 48-year-old woman on warfarin has an INR of 8.5 with no bleeding.', 'correct' => 'Withhold warfarin and give oral vitamin K', 'distractors' => ['Continue the current warfarin dose', 'Give four-factor prothrombin complex concentrate immediately', 'Start therapeutic heparin'], 'explanation' => 'A markedly raised INR without bleeding is managed by withholding warfarin and giving oral vitamin K.'],

            // ── Infectious Diseases ───────────────────────────────────────────
            ['system' => 'Infectious Diseases', 'frame' => 'A 21-year-old has fever, a non-blanching purpuric rash, neck stiffness and hypotension.', 'correct' => 'Immediate intravenous ceftriaxone and fluid resuscitation', 'distractors' => ['Await lumbar puncture before any antibiotic', 'Oral antibiotics in the community', 'Antiviral therapy alone'], 'explanation' => 'Suspected meningococcal sepsis is treated with immediate parenteral antibiotics; imaging must not delay them.'],
            ['system' => 'Infectious Diseases', 'frame' => 'A 54-year-old returning from sub-Saharan Africa has a fever and ring forms on a thin blood film.', 'correct' => 'Urgent antimalarial therapy guided by species and parasitaemia', 'distractors' => ['Oral amoxicillin', 'Discharge with paracetamol', 'Await a second film in a week'], 'explanation' => 'Falciparum malaria is a medical emergency and is treated urgently once parasites are seen.'],
            ['system' => 'Infectious Diseases', 'frame' => 'A 38-year-old intravenous drug user has a new murmur, splinter haemorrhages and three positive blood cultures.', 'correct' => 'Prolonged intravenous antibiotics with echocardiography', 'distractors' => ['Single-dose oral antibiotic', 'Discharge with outpatient review in a month', 'Immediate valve replacement before cultures'], 'explanation' => 'Infective endocarditis requires prolonged intravenous antibiotics and echocardiographic assessment.'],

            // ── General Surgery ───────────────────────────────────────────────
            ['system' => 'General Surgery', 'frame' => 'A 19-year-old man has right iliac fossa pain, anorexia, a low-grade fever and localised peritonism.', 'correct' => 'Appendicectomy', 'distractors' => ['Discharge with oral analgesia', 'Colonoscopy today', 'High-dose corticosteroids'], 'explanation' => 'Clinical appendicitis with localised peritonism is managed operatively.'],
            ['system' => 'General Surgery', 'frame' => 'A 72-year-old man has absolute constipation, distension, vomiting and a tender irreducible groin lump.', 'correct' => 'Emergency surgical exploration for a strangulated hernia', 'distractors' => ['Outpatient hernia repair in three months', 'Manual reduction under sedation and discharge', 'Laxatives and review'], 'explanation' => 'Obstruction with an irreducible tender hernia indicates strangulation and needs emergency surgery.'],
            ['system' => 'General Surgery', 'frame' => 'A 63-year-old woman has sudden severe abdominal pain, board-like rigidity and free air under the diaphragm.', 'correct' => 'Resuscitation and emergency laparotomy', 'distractors' => ['Oral proton pump inhibitor and discharge', 'Barium meal', 'Observation for 48 hours'], 'explanation' => 'Pneumoperitoneum with peritonitis indicates a perforated viscus requiring emergency laparotomy.'],
            ['system' => 'General Surgery', 'frame' => 'A 55-year-old man has intermittent claudication at 200 metres with an ankle-brachial pressure index of 0.7.', 'correct' => 'Supervised exercise therapy with risk factor modification', 'distractors' => ['Immediate above-knee amputation', 'Bed rest and limb elevation', 'Therapeutic anticoagulation alone'], 'explanation' => 'Stable claudication is first managed with supervised exercise and cardiovascular risk reduction.'],

            // ── Musculoskeletal ───────────────────────────────────────────────
            ['system' => 'Musculoskeletal', 'frame' => 'A 68-year-old woman falls and has a shortened externally rotated leg with groin pain and cannot weight bear.', 'correct' => 'Pelvic radiograph and orthopaedic referral for hip fracture', 'distractors' => ['Discharge with physiotherapy', 'MRI of the lumbar spine first', 'Immediate hip arthroscopy'], 'explanation' => 'A shortened externally rotated limb after a fall indicates hip fracture; plain films and surgery follow.'],
            ['system' => 'Musculoskeletal', 'frame' => 'A 54-year-old man has a hot swollen knee with a fever and raised inflammatory markers.', 'correct' => 'Joint aspiration before antibiotics', 'distractors' => ['Intra-articular corticosteroid injection', 'Oral colchicine without aspiration', 'Discharge with rest and ice'], 'explanation' => 'Septic arthritis must be excluded by aspiration before antibiotics or steroids are given.'],
            ['system' => 'Musculoskeletal', 'frame' => 'A 36-year-old man has three months of inflammatory back pain with morning stiffness improving on exercise.', 'correct' => 'MRI of the sacroiliac joints and non-steroidal anti-inflammatory therapy', 'distractors' => ['Strict bed rest for six weeks', 'Immediate spinal fusion', 'Oral antibiotics'], 'explanation' => 'Inflammatory back pain suggests axial spondyloarthritis; MRI and NSAIDs are the initial approach.'],

            // ── Paediatrics ───────────────────────────────────────────────────
            ['system' => 'Paediatrics', 'frame' => 'A 3-year-old has a barking cough, inspiratory stridor at rest and mild recession.', 'correct' => 'Oral dexamethasone', 'distractors' => ['Immediate intubation in all cases', 'Oral amoxicillin', 'Throat examination with a spatula'], 'explanation' => 'Croup with stridor at rest is treated with a single dose of oral corticosteroid.'],
            ['system' => 'Paediatrics', 'frame' => 'A 6-week-old has projectile non-bilious vomiting after feeds and a palpable olive-shaped epigastric mass.', 'correct' => 'Correct the alkalosis then perform pyloromyotomy', 'distractors' => ['Thickened feeds and discharge', 'Oral antibiotics', 'Immediate laparotomy without resuscitation'], 'explanation' => 'Pyloric stenosis is resuscitated and its alkalosis corrected before pyloromyotomy.'],
            ['system' => 'Paediatrics', 'frame' => 'An 8-month-old has a fever, coryza, widespread wheeze and fine crepitations in winter.', 'correct' => 'Supportive care with feeding and oxygen as required', 'distractors' => ['Intravenous antibiotics for all cases', 'Nebulised adrenaline as routine first line', 'Oral corticosteroids as definitive therapy'], 'explanation' => 'Bronchiolitis is managed supportively; antibiotics and steroids do not alter its course.'],

            // ── Obstetrics & Gynaecology ──────────────────────────────────────
            ['system' => 'Obstetrics and Gynaecology', 'frame' => 'A 28-year-old woman at 34 weeks has a blood pressure of 165/110 mmHg, proteinuria and visual disturbance.', 'correct' => 'Antihypertensive therapy with magnesium sulfate and planned delivery', 'distractors' => ['Discharge with weekly review', 'Immediate oral iron', 'Fluid loading with three litres of saline'], 'explanation' => 'Severe pre-eclampsia with neurological features needs blood pressure control, seizure prophylaxis and delivery.'],
            ['system' => 'Obstetrics and Gynaecology', 'frame' => 'A 24-year-old woman has six weeks of amenorrhoea, unilateral pelvic pain and a positive pregnancy test.', 'correct' => 'Transvaginal ultrasound to exclude ectopic pregnancy', 'distractors' => ['Reassurance and review in a month', 'Immediate termination of pregnancy', 'Oral antibiotics alone'], 'explanation' => 'Pain with a positive test requires ultrasound localisation because of the risk of ectopic pregnancy.'],
            ['system' => 'Obstetrics and Gynaecology', 'frame' => 'A 31-year-old woman has torrential bleeding one hour after a vaginal delivery with a soft uterus.', 'correct' => 'Uterine massage with uterotonic drugs and resuscitation', 'distractors' => ['Observation for two hours', 'Immediate hysterectomy as first line', 'Oral tranexamic acid alone'], 'explanation' => 'Atonic postpartum haemorrhage is treated with massage, uterotonics and simultaneous resuscitation.'],

            // ── Psychiatry ────────────────────────────────────────────────────
            ['system' => 'Psychiatry', 'frame' => 'A 23-year-old man has six months of auditory hallucinations, delusions of control and social withdrawal.', 'correct' => 'Antipsychotic therapy with early intervention service referral', 'distractors' => ['Benzodiazepines as sole long-term therapy', 'Electroconvulsive therapy as first line', 'No treatment and annual review'], 'explanation' => 'First-episode psychosis is treated with an antipsychotic alongside early intervention team involvement.'],
            ['system' => 'Psychiatry', 'frame' => 'A 44-year-old woman on an antipsychotic develops rigidity, a fever of 39.5 degrees and a creatine kinase of 4000 units per litre.', 'correct' => 'Stop the antipsychotic and give supportive cooling and fluids', 'distractors' => ['Increase the antipsychotic dose', 'Start a second antipsychotic', 'Discharge with oral antibiotics'], 'explanation' => 'Neuroleptic malignant syndrome requires the drug to be stopped and aggressive supportive care.'],

            // ── Dermatology ───────────────────────────────────────────────────
            ['system' => 'Dermatology', 'frame' => 'A 60-year-old man has an enlarging pigmented lesion with an irregular border and recent colour change.', 'correct' => 'Urgent excision biopsy', 'distractors' => ['Cryotherapy without histology', 'Topical corticosteroid and review in a year', 'Reassurance'], 'explanation' => 'A changing pigmented lesion is excised for histology because of the possibility of melanoma.'],
            ['system' => 'Dermatology', 'frame' => 'A 29-year-old develops widespread target lesions and mucosal ulceration two weeks after starting lamotrigine.', 'correct' => 'Stop the drug and admit for supportive care', 'distractors' => ['Continue the drug and add an antihistamine', 'Topical emollient alone as an outpatient', 'Increase the drug dose'], 'explanation' => 'A severe mucocutaneous drug reaction requires the culprit to be stopped and inpatient supportive management.'],

            // ── Ophthalmology ─────────────────────────────────────────────────
            ['system' => 'Ophthalmology', 'frame' => 'A 66-year-old woman has a painful red eye, a fixed semi-dilated pupil, a hazy cornea and vomiting.', 'correct' => 'Urgent intraocular pressure reduction for acute angle closure', 'distractors' => ['Topical corticosteroid alone', 'Mydriatic eye drops', 'Discharge with simple analgesia'], 'explanation' => 'Acute angle-closure glaucoma threatens vision and demands urgent pressure-lowering treatment.'],
            ['system' => 'Ophthalmology', 'frame' => 'A 70-year-old man has sudden painless loss of vision with a pale retina and a cherry-red spot.', 'correct' => 'Immediate ophthalmology referral for central retinal artery occlusion', 'distractors' => ['Topical antibiotics', 'Reassurance and review in a month', 'Oral antihistamines'], 'explanation' => 'Central retinal artery occlusion is a stroke of the eye and needs immediate specialist assessment.'],
        ];
    }

    /**
     * Additional distinct stems, so the bank can fill a hundred-row workbook
     * without repeating a question text.
     *
     * Each presentation is pinned to the scenario it varies, so the single best
     * answer stays the single best answer.
     *
     * @return list<string>
     */
    private static function presentations(): array
    {
        return [
            'What is the single most appropriate immediate management?',
            'Which is the single most appropriate next step?',
            'What is the most appropriate initial investigation or treatment?',
            'Which of the following is the single best management option?',
            'What should be done first?',
        ];
    }

    /**
     * The bank, as rows ready to be written to a workbook.
     *
     * @param  int  $count  How many items to return; the bank cycles presentations to reach it.
     * @param  string  $batch  Distinguishes one generated run's texts from another's.
     * @return list<array{system: string, text: string, options: list<string>, correct: int, explanation: string, difficulty: string, exam: string, year: string, source: string}>
     */
    public static function items(int $count, string $batch = 'A'): array
    {
        $scenarios = self::scenarios();
        $presentations = self::presentations();

        $difficulties = ['Easy', 'Medium', 'Hard'];
        $exams = ['MRCP', 'MRCS', 'UKMLA'];
        $years = ['2021', '2022', '2023', '2024', '2025'];
        $sources = ['Royal College Question Bank', 'Regional Teaching Series', 'Past Paper Archive', 'Faculty Written Item'];

        $items = [];
        $index = 0;

        while (count($items) < $count) {
            $scenario = $scenarios[$index % count($scenarios)];
            $round = intdiv($index, count($scenarios));
            $presentation = $presentations[$round % count($presentations)];

            // Options are rotated so the correct answer is not always in the
            // same column: an importer that silently assumed "A" would pass a
            // fixture that always answered A.
            $options = self::rotate(
                [$scenario['correct'], ...$scenario['distractors']],
                $index % 4,
            );

            $correctIndex = (int) array_search($scenario['correct'], $options, true);

            $items[] = [
                'system' => $scenario['system'],
                'text' => sprintf(
                    '%s%s-Q%03d %s %s',
                    self::MARKER,
                    $batch,
                    count($items) + 1,
                    $scenario['frame'],
                    $presentation,
                ),
                'options' => $options,
                'correct' => $correctIndex,
                'explanation' => $scenario['explanation'],
                'difficulty' => $difficulties[$index % 3],
                'exam' => $exams[$index % 3 === 0 ? $index % 3 : ($index + $round) % 3],
                'year' => $years[$index % 5],
                'source' => $sources[$index % 4],
            ];

            $index++;
        }

        return $items;
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private static function rotate(array $options, int $by): array
    {
        $by %= count($options);

        return array_values([...array_slice($options, $by), ...array_slice($options, 0, $by)]);
    }
}

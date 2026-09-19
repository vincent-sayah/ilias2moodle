from __future__ import annotations

import zipfile
from pathlib import Path

from ilias2moodle.ilias.exercise import parse_exercises


def test_exercise_parser_reads_units_types_files_and_constraints(tmp_path: Path) -> None:
    archive_path = tmp_path / "course.zip"
    base = "set_32/1800000000__0__exc_910"

    with zipfile.ZipFile(archive_path, "w") as archive:
        archive.writestr(
            "manifest.xml",
            """<Manifest>
<ExportSet Path='set_1/1800000000__0__crs_504' Type='crs'/>
<ExportSet Path='set_32/1800000000__0__exc_910' Type='exc'/>
</Manifest>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/Exercise/set_0/export.xml",
            """<Export><ExportItem Id='910'><DataSet>
<Rec Entity='exc'><Exc>
<Id>910</Id><Title>Exercice POC</Title><Description>Consignes générales</Description>
<PassMode>all</PassMode><PassNr>2</PassNr><NrMandatoryRandom>0</NrMandatoryRandom>
<ShowSubmissions>1</ShowSubmissions><ComplBySubmission>1</ComplBySubmission><Tfeedback>0</Tfeedback>
</Exc></Rec>
<Rec Entity='exc_assignment'><ExcAssignment>
<Id>21</Id><ExerciseId>910</ExerciseId><Type>1</Type>
<StartTime>2026-09-20 08:00:00</StartTime><Deadline>2026-09-25 18:00:00</Deadline>
<Deadline2>2026-09-26 18:00:00</Deadline2><Instruction>Déposer le PDF.</Instruction>
<Title>Dépôt fichier</Title><Mandatory>1</Mandatory><OrderNr>10</OrderNr>
<TeamTutor>0</TeamTutor><MaxFile>2</MaxFile>
<InstructionCollection>components/ILIAS/Exercise/set_0/dsDir_1</InstructionCollection>
<Peer>0</Peer><DeadlineMode>0</DeadlineMode>
</ExcAssignment></Rec>
<Rec Entity='exc_assignment'><ExcAssignment>
<Id>22</Id><ExerciseId>910</ExerciseId><Type>5</Type>
<Instruction>Répondre dans l'éditeur.</Instruction><Title>Texte en ligne</Title>
<Mandatory>1</Mandatory><OrderNr>20</OrderNr><Peer>0</Peer><DeadlineMode>0</DeadlineMode>
</ExcAssignment></Rec>
<Rec Entity='exc_assignment'><ExcAssignment>
<Id>23</Id><ExerciseId>910</ExerciseId><Type>4</Type>
<Instruction>Travail en équipe.</Instruction><Title>Dépôt équipe</Title>
<Mandatory>0</Mandatory><OrderNr>30</OrderNr><Peer>0</Peer><DeadlineMode>0</DeadlineMode>
</ExcAssignment></Rec>
<Rec Entity='exc_ass_file_order'><ExcAssFileOrder>
<Id>1</Id><AssignmentId>21</AssignmentId><Filename>consigne.pdf</Filename><OrderNr>10</OrderNr>
</ExcAssFileOrder></Rec>
<Rec Entity='exc_ass_reminders'><ExcAssReminders>
<Type>deadline</Type><AssignmentId>22</AssignmentId><ExerciseId>910</ExerciseId>
<Status>1</Status><Start>3</Start><End>2026-09-24 00:00:00</End>
<Frequency>1</Frequency><LastSend></LastSend><TemplateId>0</TemplateId>
</ExcAssReminders></Rec>
</DataSet></ExportItem></Export>""",
        )
        archive.writestr(
            f"{base}/components/ILIAS/Exercise/set_0/dsDir_1/consigne.pdf",
            b"pdf",
        )

    exercises = parse_exercises(archive_path)
    assert len(exercises) == 1
    exercise = exercises[0]

    assert exercise["source"]["object_id"] == "910"
    assert exercise["title"] == "Exercice POC"
    assert len(exercise["assignments"]) == 3

    upload = exercise["assignments"][0]
    assert upload["source_id"] == "21"
    assert upload["type"]["key"] == "file_upload"
    assert upload["automatic_ready"] is True
    assert upload["max_files"] == 2
    assert upload["instruction_files"][0]["filename"] == "consigne.pdf"
    assert upload["instruction_files"][0]["archive_path"].endswith("consigne.pdf")

    text = exercise["assignments"][1]
    assert text["type"]["key"] == "online_text"
    assert text["automatic_ready"] is False
    assert "assignment_reminders" in text["migration_constraints"]

    team = exercise["assignments"][2]
    assert team["type"]["key"] == "team_file_upload"
    assert team["type"]["migration_support"] == "phase7_group_dependency"
    assert team["automatic_ready"] is False

    assert exercise["user_data_policy"]["submissions_migrated"] is False
    assert exercise["target_strategy"]["confirmed_by_real_poc"] is False

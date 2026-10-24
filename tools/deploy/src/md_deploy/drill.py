"""The local rehearsal: switch → rollback → switch again (spec 20 §3.3 step 0).

The deploy window's precondition is that the pipeline has been drilled on a
local instance. This module is that drill: it runs the same `steps` the
window runs against a local instance, rolls the instance back with the same
rollback, and runs the deploy a second time. What it asserts is what the
window depends on:

- the rollback puts the site back — the mu-plugins tree, the plugin face and
  the home page all return to what they were;
- the second pass ends where the first one did, so the window is not a
  one-shot that cannot be re-run.

The full `verify` entry-point sweep is the deploy window's, not the drill's:
the local instance carries no content before the import ticket runs, and the
drill exists to prove the machinery — the switch, the snapshot, the rollback —
not the pages.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import UTC, datetime
from pathlib import Path

from md_deploy import atomic, plan, steps
from md_deploy.target import DeployError, Target


@dataclass
class Drill:
    """One rehearsal, and everything it observed."""

    target: Target
    repository: Path
    home: Path
    staging: Path
    steps: list[dict[str, object]] = field(default_factory=list)

    def run(self) -> dict[str, object]:
        """Run the whole rehearsal and return its record."""
        before = self.fingerprint()
        print("drill: first pass")
        shipped = self._pass()
        after_first = self.fingerprint()

        print("drill: rollback")
        receipt = atomic.rollback(self.target, list(shipped))
        self.steps.append(
            {
                "step": "rollback-deploy",
                "actions": [f"deploy id: {receipt.deploy_id}", *receipt.readbacks],
                "notes": [],
            }
        )
        restored = self.fingerprint()
        if restored != before:
            raise DeployError(
                "the rollback did not restore the pre-deploy state: "
                + _difference(before, restored)
            )

        print("drill: second pass")
        self._pass()
        after_second = self.fingerprint()
        if after_second != after_first:
            raise DeployError(
                "the second pass ended in a different state: "
                + _difference(after_first, after_second)
            )

        return {
            "target": self.target.label,
            "run_at": datetime.now(UTC).isoformat(),
            "before": before,
            "after_first": after_first,
            "after_rollback": restored,
            "after_second": after_second,
            "steps": self.steps,
        }

    def fingerprint(self) -> dict[str, object]:
        """The state the drill compares: what is installed and what answers."""
        answers = {}
        status, _ = self.target.http("/", cache_buster="drill")
        answers["/"] = status
        products = self.target.wp(
            "post",
            "list",
            "--post_type=product",
            "--post_status=publish",
            "--format=count",
            check=False,
        ).strip()
        return {
            "plugins": steps.plugin_face(self.target),
            "published_products": products,
            "answers": answers,
            # The switch writes here (the recorder and the guards are installed
            # over the live files, the reset lands new), so the rollback has to
            # be able to put the directory back: the drill asserts that it did.
            # The deploy's own sibling markers (dot-prefixed `.name.prev*`
            # files) are bookkeeping, not site state — each batch's markers
            # carry that batch's id, so counting them would fail every second
            # pass by design. No live must-use file starts with a dot.
            "must_use": sorted(
                entry
                for entry in self.target.tree(plan.MU_PLUGINS_DIR)
                if not Path(entry.rsplit("  ", 1)[-1]).name.startswith(".")
            ),
            # The child theme ships as one tree; through the dev instance's
            # symlink and the deployed real directory alike, the content the
            # site serves must be the same after every phase.
            "child_theme": sorted(self.target.tree(plan.CHILD_THEME_TARGET)),
        }

    def _pass(self) -> tuple[str, ...]:
        """One deploy pass; returns the batch's own paths from its receipt."""
        deployed = steps.deploy(
            self.target,
            repository=self.repository,
            staging=self.staging,
            snapshot_home=self.home,
        )
        self.steps.append(
            {
                "step": deployed.step,
                "actions": deployed.actions,
                "notes": deployed.notes,
            }
        )
        assert deployed.publication is not None
        # The receipt's paths, not a recomputed inventory: the rollback must
        # restore exactly the batch that was deployed, whatever the tree
        # looks like by then.
        return deployed.publication.paths


def _difference(before: dict[str, object], after: dict[str, object]) -> str:
    """What changed between two fingerprints, for the failure message."""
    changed = []
    for key in sorted(set(before) | set(after)):
        if before.get(key) != after.get(key):
            changed.append(f"{key}: {before.get(key)!r} -> {after.get(key)!r}")
    return "; ".join(changed) or "nothing"


def write(record: dict[str, object], destination: Path) -> Path:
    """Write a drill's record under the data root."""
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(
        json.dumps(record, indent=1, sort_keys=True) + "\n", encoding="utf-8"
    )
    return destination

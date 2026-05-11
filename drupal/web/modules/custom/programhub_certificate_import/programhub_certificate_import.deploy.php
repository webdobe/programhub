<?php

/**
 * @file
 * Deploy hooks for programhub_certificate_import.
 *
 * `drush deploy` runs hooks in this order:
 *   1. updatedb        — hook_update_N + hook_post_update_NAME
 *   2. config:import   — applies YAML in config/sync/
 *   3. cache:rebuild
 *   4. deploy:hook     — runs hook_deploy_NAME (this file)
 *
 * Anything in here can rely on configuration added in the same deploy:
 * field_logo (new on certificate), the pathauto.pattern.certificates pattern,
 * etc. If we put this in *post_update* it would run BEFORE cim and the new
 * pathauto pattern wouldn't be available yet — alias regen would fail.
 *
 * All hooks must be idempotent: prod deploys, snapshot restores, and fresh
 * environment builds may all replay them.
 */

declare(strict_types=1);

use Drupal\group\Entity\Group;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Seed the "Industry Certification" certificate_type taxonomy term.
 *
 * This term distinguishes vendor exam credentials (CompTIA Security+,
 * Cisco CCNA, Microsoft AZ-900, …) from the existing academic credential
 * terms (BTC, ITC, ATC, AAS, AS).
 *
 * Numeric prefix forces ordering — Drupal runs deploy hooks alphabetically
 * by function name, and the cert-node seed below depends on this term.
 */
function programhub_certificate_import_deploy_01_industry_cert_term(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $existing = $storage->loadByProperties([
    'vid' => 'certificate_type',
    'name' => 'Industry Certification',
  ]);
  if ($existing) {
    return 'Industry Certification term already exists; skipped.';
  }
  $term = Term::create([
    'vid' => 'certificate_type',
    'name' => 'Industry Certification',
    'description' => [
      'value' => 'Third-party / vendor exam credential (CompTIA, Cisco, Microsoft, etc.) — earned by passing the vendor exam, not by completing NIC coursework.',
      'format' => 'basic_html',
    ],
  ]);
  $term->save();
  return sprintf('Created Industry Certification term (tid=%d).', $term->id());
}

/**
 * Seed the initial industry certification nodes for the CITE + CYBER programs.
 *
 * Each node:
 *  - title:                  vendor cert name (e.g. "CompTIA Security+")
 *  - field_certificate_type: Industry Certification term
 *  - field_certificate_url:  vendor exam page (used as the canonical link)
 *  - attached to:            program groups that prepare students for this cert
 *
 * Logos (field_logo) are intentionally not set here — editors upload them
 * via the admin UI once the appropriate logo media is in place.
 */
function programhub_certificate_import_deploy_02_industry_cert_nodes(array &$sandbox): string {
  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
  $termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

  // Resolve the Industry Certification term tid.
  $type = $termStorage->loadByProperties([
    'vid' => 'certificate_type',
    'name' => 'Industry Certification',
  ]);
  if (!$type) {
    return 'Industry Certification term missing — run the 01_industry_cert_term deploy hook first.';
  }
  $typeTid = (int) array_keys($type)[0];

  // Resolve programs by their abbreviation. Skip the entire seed if both
  // programs are absent — cleaner than partial seeding on a fresh database.
  $cite = _programhub_certificate_import_program_group('CITE');
  $cyber = _programhub_certificate_import_program_group('CYBER');
  if (!$cite && !$cyber) {
    return 'No CITE or CYBER programs found; skipping industry cert seed.';
  }

  /**
   * @return \Drupal\group\Entity\GroupInterface[]
   */
  $programs = static function (array $abbrs) use ($cite, $cyber): array {
    $groups = [];
    foreach ($abbrs as $abbr) {
      if ($abbr === 'CITE' && $cite) {
        $groups[] = $cite;
      }
      if ($abbr === 'CYBER' && $cyber) {
        $groups[] = $cyber;
      }
    }
    return $groups;
  };

  $seed = [
    ['CompTIA Security+', 'https://www.comptia.org/certifications/security', ['CITE', 'CYBER']],
    ['CompTIA Network+', 'https://www.comptia.org/certifications/network', ['CITE', 'CYBER']],
    ['CompTIA A+', 'https://www.comptia.org/certifications/a', ['CITE']],
    ['CompTIA CySA+', 'https://www.comptia.org/certifications/cybersecurity-analyst', ['CYBER']],
    ['Cisco CCNA', 'https://www.cisco.com/site/us/en/learn/training-certifications/certifications/enterprise/ccna/index.html', ['CYBER']],
    ['Microsoft AZ-900 (Azure Fundamentals)', 'https://learn.microsoft.com/en-us/credentials/certifications/azure-fundamentals/', ['CITE']],
    ['Microsoft SC-900 (Security, Compliance, and Identity Fundamentals)', 'https://learn.microsoft.com/en-us/credentials/certifications/security-compliance-and-identity-fundamentals/', ['CITE']],
    ['Microsoft MD-100 / MD-101 (Modern Desktop Administrator)', 'https://learn.microsoft.com/en-us/credentials/certifications/modern-desktop/', ['CITE']],
  ];

  $created = 0;
  $skipped = 0;
  foreach ($seed as [$title, $url, $abbrs]) {
    $existing = $nodeStorage->loadByProperties([
      'type' => 'certificate',
      'title' => $title,
    ]);
    if ($existing) {
      $skipped++;
      continue;
    }
    $programGroups = $programs($abbrs);
    if (!$programGroups) {
      $skipped++;
      continue;
    }
    $node = Node::create([
      'type' => 'certificate',
      'title' => $title,
      'status' => 1,
      'field_certificate_type' => ['target_id' => $typeTid],
      'field_certificate_url' => ['uri' => $url, 'title' => 'Vendor exam page'],
    ]);
    $node->path->pathauto = 1;
    $node->save();
    // Attach the cert to each owning program via group_relationship.
    foreach ($programGroups as $group) {
      $group->addRelationship($node, 'group_node:certificate');
    }
    $created++;
  }

  return sprintf('Industry cert nodes — created: %d, skipped (already present): %d.', $created, $skipped);
}

/**
 * Regenerate path aliases for every existing certificate node.
 *
 * Why: the pathauto.pattern.certificates pattern is added in the same deploy
 * via config sync, but pathauto only fires on entity save. Existing
 * certificate nodes (academic credentials imported before this deploy) will
 * still resolve at /node/<nid> until they're resaved with pathauto enabled.
 */
function programhub_certificate_import_deploy_03_regenerate_certificate_aliases(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nodes = $storage->loadByProperties(['type' => 'certificate']);
  $count = 0;
  foreach ($nodes as $node) {
    $node->path->pathauto = 1;
    $node->save();
    $count++;
  }
  return sprintf('Regenerated path aliases for %d certificate nodes.', $count);
}

/**
 * Seed initial cert → prep-course relationships for the 8 industry certs.
 *
 * Maps each industry cert to the NIC catalog courses that prepare a student
 * to sit the exam. Initial mappings reflect typical CITE department coverage;
 * editors can refine via the admin UI without losing this baseline (we only
 * write when field_prep_courses is empty, never overwrite existing edits).
 */
function programhub_certificate_import_deploy_04_industry_cert_prep_courses(array &$sandbox): string {
  // cert title → list of prep-course numbers. Numbers are normalized to the
  // catalog "PREFIX-NUMBER" form. Unknown numbers are silently skipped per cert.
  $map = [
    'CompTIA Security+' => ['CITE-140', 'CITE-142', 'CITE-145'],
    'CompTIA Network+' => ['CITE-152', 'CITE-121', 'CITE-122'],
    'CompTIA A+' => ['CITE-118', 'CITE-119', 'CITE-127'],
    'CompTIA CySA+' => ['CITE-140', 'CITE-142', 'CITE-213', 'CITE-217'],
    'Cisco CCNA' => ['CITE-152', 'CITE-121', 'CITE-213', 'CITE-217'],
    'Microsoft AZ-900 (Azure Fundamentals)' => ['CITE-104', 'CITE-206'],
    'Microsoft SC-900 (Security, Compliance, and Identity Fundamentals)' => ['CITE-140', 'CITE-142', 'CITE-208'],
    'Microsoft MD-100 / MD-101 (Modern Desktop Administrator)' => ['CITE-116', 'CITE-127'],
  ];

  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

  // Pre-resolve every course number we'll need to a node id.
  $allNumbers = array_unique(array_merge(...array_values($map)));
  $courseIdsByNumber = [];
  foreach ($allNumbers as $num) {
    $found = $nodeStorage->loadByProperties([
      'type' => 'course',
      'field_course_number' => $num,
    ]);
    if ($found) {
      $courseIdsByNumber[$num] = (int) array_keys($found)[0];
    }
  }

  $updated = 0;
  $skipped = 0;
  $missingByCert = [];
  foreach ($map as $certTitle => $prepNumbers) {
    $certs = $nodeStorage->loadByProperties([
      'type' => 'certificate',
      'title' => $certTitle,
    ]);
    if (!$certs) {
      $skipped++;
      continue;
    }
    /** @var \Drupal\node\NodeInterface $cert */
    $cert = reset($certs);

    // Idempotent: don't clobber editor-set values.
    if (!$cert->get('field_prep_courses')->isEmpty()) {
      $skipped++;
      continue;
    }

    $targetIds = [];
    foreach ($prepNumbers as $num) {
      if (isset($courseIdsByNumber[$num])) {
        $targetIds[] = ['target_id' => $courseIdsByNumber[$num]];
      }
      else {
        $missingByCert[$certTitle][] = $num;
      }
    }
    if (!$targetIds) {
      $skipped++;
      continue;
    }
    $cert->set('field_prep_courses', $targetIds);
    $cert->save();
    $updated++;
  }

  $report = sprintf('Industry-cert prep courses — updated: %d, skipped (already set or missing cert): %d.', $updated, $skipped);
  if ($missingByCert) {
    $bits = [];
    foreach ($missingByCert as $title => $nums) {
      $bits[] = sprintf('"%s" missing courses: %s', $title, implode(', ', $nums));
    }
    $report .= ' Course-resolution gaps: ' . implode(' · ', $bits);
  }
  return $report;
}

/**
 * Seed credential-type abbreviations on the certificate_type taxonomy terms.
 * Used by the alias rebuild below to produce URLs like
 * /certificates/computer-information-technology-aas.
 */
function programhub_certificate_import_deploy_05_certificate_type_abbreviations(array &$sandbox): string {
  $map = [
    'Basic Technical Certificate' => 'BTC',
    'Intermediate Technical Certificate' => 'ITC',
    'Advanced Technical Certificate' => 'ATC',
    'Associate of Applied Science Degree' => 'AAS',
    // Industry Certification intentionally absent — its abbreviation is left
    // blank, which the alias builder treats as "no suffix needed".
  ];
  $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $updated = 0;
  $skipped = 0;
  foreach ($map as $name => $abbr) {
    $found = $storage->loadByProperties([
      'vid' => 'certificate_type',
      'name' => $name,
    ]);
    if (!$found) {
      $skipped++;
      continue;
    }
    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = reset($found);
    if (!$term->hasField('field_abbreviation')) {
      $skipped++;
      continue;
    }
    if (!$term->get('field_abbreviation')->isEmpty()) {
      $skipped++;
      continue;
    }
    $term->set('field_abbreviation', $abbr);
    $term->save();
    $updated++;
  }
  return sprintf('Type abbreviations — set: %d, skipped: %d.', $updated, $skipped);
}

/**
 * Rebuild every certificate's path alias.
 *
 * Saves each cert through hook_node_presave (in
 * programhub_certificate_import.module), which is the canonical place where
 * cert aliases get computed. This hook also drops any stale aliases left
 * from earlier slug schemes (e.g. `-0`, `-1` suffixes from pathauto's
 * disambiguation pass).
 *
 * Idempotent: re-running this when aliases are already correct is a no-op
 * because the presave hook only writes when the alias differs.
 */
/**
 * Re-seed industry-cert → prep-course mappings.
 *
 * Same body as `_04_industry_cert_prep_courses` — exists under a fresh hook
 * name so Drupal will run it again on environments where field_prep_courses
 * got accidentally cleared (e.g. a snapshot restore or manual blow-away).
 * Idempotent: only writes where field_prep_courses is currently empty, so
 * editor-set values are preserved.
 *
 * If the data gets wiped *again* later, the recipe is to add the next
 * sequential `_NN_reseed_industry_cert_prep_courses` (e.g. `_09`, `_10`).
 * Drupal tracks deploy-hook completion by function name, so each new
 * suffix triggers a fresh execution.
 */
function programhub_certificate_import_deploy_07_reseed_industry_cert_prep_courses(array &$sandbox): string {
  return programhub_certificate_import_deploy_04_industry_cert_prep_courses($sandbox);
}

/**
 * Force-rerun of the industry-cert → prep-course seed.
 *
 * `_07` was committed but on prod it ran once (or aborted in the same
 * deploy as `_06`) and didn't populate the field. This duplicate-named
 * hook gives Drupal a new function-name to track, so deploy:hook will
 * execute it on the next run.
 */
function programhub_certificate_import_deploy_08_reseed_industry_cert_prep_courses(array &$sandbox): string {
  return programhub_certificate_import_deploy_04_industry_cert_prep_courses($sandbox);
}

/**
 * Force-attach industry-cert → prep-course mappings, overwriting whatever's
 * there. Unlike `_04`/`_07`/`_08` which skip when field_prep_courses already
 * has values, this one ALWAYS writes the canonical map. Needed when the
 * referenced course nodes got recreated with fresh node ids (e.g. courses
 * were deleted and re-imported), leaving the certs with non-empty but
 * dangling references that the skip-if-non-empty guard never refreshes.
 */
function programhub_certificate_import_deploy_09_force_reattach_industry_cert_prep_courses(array &$sandbox): string {
  $map = [
    'CompTIA Security+' => ['CITE-140', 'CITE-142', 'CITE-145'],
    'CompTIA Network+' => ['CITE-152', 'CITE-121', 'CITE-122'],
    'CompTIA A+' => ['CITE-118', 'CITE-119', 'CITE-127'],
    'CompTIA CySA+' => ['CITE-140', 'CITE-142', 'CITE-213', 'CITE-217'],
    'Cisco CCNA' => ['CITE-152', 'CITE-121', 'CITE-213', 'CITE-217'],
    'Microsoft AZ-900 (Azure Fundamentals)' => ['CITE-104', 'CITE-206'],
    'Microsoft SC-900 (Security, Compliance, and Identity Fundamentals)' => ['CITE-140', 'CITE-142', 'CITE-208'],
    'Microsoft MD-100 / MD-101 (Modern Desktop Administrator)' => ['CITE-116', 'CITE-127'],
  ];

  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

  $allNumbers = array_unique(array_merge(...array_values($map)));
  $courseIdsByNumber = [];
  foreach ($allNumbers as $num) {
    $found = $nodeStorage->loadByProperties([
      'type' => 'course',
      'field_course_number' => $num,
    ]);
    if ($found) {
      $courseIdsByNumber[$num] = (int) array_keys($found)[0];
    }
  }

  $written = 0;
  $missingCerts = [];
  $missingCourses = [];
  foreach ($map as $certTitle => $prepNumbers) {
    $certs = $nodeStorage->loadByProperties([
      'type' => 'certificate',
      'title' => $certTitle,
    ]);
    if (!$certs) {
      $missingCerts[] = $certTitle;
      continue;
    }
    $cert = reset($certs);

    $targetIds = [];
    foreach ($prepNumbers as $num) {
      if (isset($courseIdsByNumber[$num])) {
        $targetIds[] = ['target_id' => $courseIdsByNumber[$num]];
      }
      else {
        $missingCourses[$certTitle][] = $num;
      }
    }
    // Write even if empty — empty is preferable to dangling refs.
    $cert->set('field_prep_courses', $targetIds);
    $cert->save();
    $written++;
  }

  $report = sprintf('Industry-cert prep courses — force-written: %d.', $written);
  if ($missingCerts) {
    $report .= ' Missing certs: ' . implode(', ', $missingCerts) . '.';
  }
  if ($missingCourses) {
    $bits = [];
    foreach ($missingCourses as $title => $nums) {
      $bits[] = sprintf('"%s" missing %s', $title, implode('/', $nums));
    }
    $report .= ' Course-resolution gaps: ' . implode(' · ', $bits);
  }
  return $report;
}

function programhub_certificate_import_deploy_06_rebuild_certificate_aliases(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');

  // Pre-pass: drop every stale alias before we touch any node. The next
  // save() then creates a fresh path_alias from scratch — no risk of the
  // node's in-memory path field holding a `pid` reference to a row that's
  // already been deleted (which is what triggered the
  // PathItem::postSave() null-getAlias error in production).
  $certIds = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'certificate')
    ->execute();
  foreach ($certIds as $nid) {
    foreach ($aliasStorage->loadByProperties(['path' => '/node/' . $nid]) as $a) {
      $a->delete();
    }
  }
  // Reset the cert storage cache so the next loadMultiple() doesn't return
  // entities that still cache the deleted aliases on $node->path.
  $storage->resetCache();

  $touched = 0;
  foreach ($storage->loadMultiple($certIds) as $cert) {
    $expected = programhub_certificate_import_compute_cert_alias($cert);
    if ($expected === '') {
      continue;
    }
    // The presave hook writes path.alias; the path field then creates a
    // brand-new path_alias on save. fieldable_path mirrors that to field_path
    // via its path_alias_insert hook.
    $cert->save();
    $touched++;
  }
  return sprintf('Certificate aliases — touched: %d (presave handled the writes).', $touched);
}

/**
 * Resolve a program Group entity by its field_abbreviation. Searches
 * every program subtype (program, program_design, program_culinary, …)
 * so retyping a base `program` to a specialized variant doesn't break
 * this lookup.
 */
function _programhub_certificate_import_program_group(string $abbr): ?\Drupal\group\Entity\GroupInterface {
  $ids = \Drupal::entityTypeManager()->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, 'IN')
    ->condition('field_abbreviation', $abbr)
    ->range(0, 1)
    ->execute();
  return $ids ? Group::load((int) reset($ids)) : NULL;
}

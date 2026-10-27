#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Unit tests for the literal checks in validate_example_set.py.

Run: python3 -I -m unittest discover -s tests/example-sets -p 'test_*.py'
"""
import importlib.util
import os
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_SPEC = importlib.util.spec_from_file_location('validate_example_set', os.path.join(_HERE, 'validate_example_set.py'))
validator = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(validator)


def problems_for(data, path='data'):
    problems = []
    validator.literal_identifiers(data, path, problems, 'object "x"')
    return problems


class EmailDomainTest(unittest.TestCase):

    def test_the_set_domain_is_accepted(self):
        self.assertEqual([], problems_for({'email': 'info.slagerij@zuiddrecht.example'}))

    def test_any_example_domain_is_accepted(self):
        self.assertEqual([], problems_for({
            'email': 'info@bakkerijdegraaf.example',
            'reporterEmail': 'm.elamrani@ziggo.example',
        }))

    def test_a_subdomain_of_example_is_accepted(self):
        self.assertEqual([], problems_for({'handle': 'zuiddrecht@social.overheid.example'}))

    def test_an_address_in_running_text_is_accepted(self):
        self.assertEqual([], problems_for({'notes': 'Mail naar balie@sportcentrum.example.'}))

    def test_a_real_domain_is_refused(self):
        problems = problems_for({'email': 'jan@gmail.com'})
        self.assertEqual(1, len(problems))
        self.assertIn('jan@gmail.com', problems[0])

    def test_a_real_domain_that_merely_contains_example_is_refused(self):
        self.assertEqual(1, len(problems_for({'email': 'jan@zuiddrecht.example.com'})))
        self.assertEqual(1, len(problems_for({'email': 'jan@notexample'})))

    def test_an_address_inside_a_placeholder_is_not_checked(self):
        self.assertEqual([], problems_for({'email': '{{cast:henk.email}}'}))

    def test_nested_values_are_checked(self):
        problems = problems_for({'contacts': [{'email': 'a@b.nl'}]})
        self.assertEqual(1, len(problems))
        self.assertIn('data.contacts[0].email', problems[0])


class LiteralIdentifierTest(unittest.TestCase):

    def test_a_literal_kvk_number_is_refused(self):
        self.assertEqual(1, len(problems_for({'kvkNumber': '00001001'})))

    def test_a_cast_kvk_number_is_accepted(self):
        self.assertEqual([], problems_for({'kvkNumber': '{{cast:slagerij.kvk}}'}))


class UserFormatTest(unittest.TestCase):

    SCHEMA = {'type': 'array', 'items': {'type': 'string', 'format': 'user'}}

    def setUp(self):
        validator.CAST_UIDS.clear()
        validator.CAST_UIDS.update({'pieter', 'lotte.mulder'})

    def errors(self, value):
        check = validator.jsonschema.Draft202012Validator(self.SCHEMA, format_checker=validator.checker)
        return [e.message for e in check.iter_errors(value)]

    def test_a_cast_uid_is_accepted(self):
        self.assertEqual([], self.errors(['pieter', 'lotte.mulder']))

    def test_a_display_name_is_refused(self):
        self.assertEqual(["'Pieter Jansen' is not a 'user'"], self.errors(['Pieter Jansen']))

    def test_an_account_outside_the_cast_is_refused(self):
        self.assertEqual(1, len(self.errors(['admin'])))


if __name__ == '__main__':
    unittest.main()
